<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withToken;

test('the full flow: consent, code, token, a working /mcp call and a refresh', function () {
    $clientId = registerOAuthClient();
    $verifier = Str::random(64);
    $educator = User::factory()->educator()->create(['name' => 'Anna Keller']);

    $consent = actingAs($educator)->get('/oauth/authorize?'.http_build_query(authorizationQuery($clientId, $verifier)));

    $consent->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('mcp/consent')
        ->where('clientName', 'Claude Code')
        ->where('userName', 'Anna Keller')
        ->where('capabilities', ['Read the progress of your groups', 'Assign content', 'Change due dates'])
        ->where('state', 'state-123')
        ->has('authToken')
        ->has('csrfToken'));

    $approval = post('/oauth/authorize', ['auth_token' => $consent->inertiaProps('authToken'), 'state' => 'state-123']);

    $callback = callbackQuery($approval, 'http://localhost:9999/callback');
    expect($callback)->toHaveKeys(['code', 'state'])
        ->and($callback['state'])->toBe('state-123');

    $tokens = post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $clientId,
        'redirect_uri' => 'http://localhost:9999/callback',
        'code_verifier' => $verifier,
        'code' => $callback['code'],
    ])->assertOk()->json();

    expect($tokens['token_type'])->toBe('Bearer')
        ->and($tokens['expires_in'])->toBeGreaterThanOrEqual(3599)->toBeLessThanOrEqual(3600)
        ->and($tokens)->toHaveKeys(['access_token', 'refresh_token']);

    app('auth')->forgetGuards();

    withToken($tokens['access_token'])
        ->postJson('/mcp', mcpRequest('tools/list'))
        ->assertOk()
        ->assertJsonIsArray('result.tools');

    $refreshed = post('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $clientId,
        'refresh_token' => $tokens['refresh_token'],
    ])->assertOk()->json();

    expect($refreshed)->toHaveKeys(['access_token', 'refresh_token'])
        ->and($refreshed['access_token'])->not->toBe($tokens['access_token'])
        ->and($refreshed['refresh_token'])->not->toBe($tokens['refresh_token']);
});

test('codes live 10 minutes, access tokens 1 hour and refresh tokens 30 days', function () {
    $clientId = registerOAuthClient();
    $verifier = Str::random(64);

    $consent = actingAs(User::factory()->educator()->create())->get('/oauth/authorize?'.http_build_query(authorizationQuery($clientId, $verifier)));
    $code = callbackQuery(post('/oauth/authorize', ['auth_token' => $consent->inertiaProps('authToken')]), 'http://localhost:9999/callback')['code'];

    expect(secondsFromNow(DB::table('oauth_auth_codes')->value('expires_at')))->toBeBetween(10 * 60 - 60, 10 * 60);

    post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $clientId,
        'redirect_uri' => 'http://localhost:9999/callback',
        'code_verifier' => $verifier,
        'code' => $code,
    ])->assertOk();

    expect(secondsFromNow(DB::table('oauth_access_tokens')->value('expires_at')))->toBeBetween(3600 - 60, 3600)
        ->and(secondsFromNow(DB::table('oauth_refresh_tokens')->value('expires_at')))->toBeBetween(30 * 86400 - 60, 30 * 86400);
});

test('the token endpoint refuses a code without the right verifier', function () {
    $clientId = registerOAuthClient();
    $verifier = Str::random(64);

    $consent = actingAs(User::factory()->educator()->create())->get('/oauth/authorize?'.http_build_query(authorizationQuery($clientId, $verifier)));
    $code = callbackQuery(post('/oauth/authorize', ['auth_token' => $consent->inertiaProps('authToken')]), 'http://localhost:9999/callback')['code'];

    post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $clientId,
        'redirect_uri' => 'http://localhost:9999/callback',
        'code_verifier' => Str::random(64),
        'code' => $code,
    ])->assertBadRequest();

    expect(DB::table('oauth_access_tokens')->count())->toBe(0);
});

test('an authorization request without a PKCE challenge gets no consent page', function () {
    $clientId = registerOAuthClient();
    $query = collect(authorizationQuery($clientId, Str::random(64)))->except(['code_challenge', 'code_challenge_method'])->all();

    actingAs(User::factory()->educator()->create())
        ->get('/oauth/authorize?'.http_build_query($query))
        ->assertBadRequest();
});

test('denying redirects with access_denied and issues nothing', function () {
    $clientId = registerOAuthClient();

    $consent = actingAs(User::factory()->educator()->create())->get('/oauth/authorize?'.http_build_query(authorizationQuery($clientId, Str::random(64))));

    $denial = delete('/oauth/authorize', ['auth_token' => $consent->inertiaProps('authToken'), 'state' => 'state-123']);

    $callback = callbackQuery($denial, 'http://localhost:9999/callback');
    expect($callback['error'])->toBe('access_denied')
        ->and($callback['state'])->toBe('state-123')
        ->and($callback)->not->toHaveKey('code')
        ->and(DB::table('oauth_auth_codes')->count())->toBe(0)
        ->and(DB::table('oauth_access_tokens')->count())->toBe(0);
});

test('a request answers once: a second answer gets the message page, not Passport\'s error', function (string $method) {
    $clientId = registerOAuthClient();
    $educator = User::factory()->educator()->create();

    $consent = actingAs($educator)->get('/oauth/authorize?'.http_build_query(authorizationQuery($clientId, Str::random(64))));
    $form = ['auth_token' => $consent->inertiaProps('authToken'), 'state' => 'state-123'];

    expect(callbackQuery(post('/oauth/authorize', $form), 'http://localhost:9999/callback'))->toHaveKey('code');

    $again = actingAs($educator)->call($method, '/oauth/authorize', $form);

    $again->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->component('mcp/message')
            ->where('message', 'This request has expired or was already answered. Start the connection again from your AI assistant.')
            ->where('signOut', false));

    expect((string) $again->getContent())->not->toContain('session auth token')
        ->and(DB::table('oauth_auth_codes')->count())->toBe(1);
})->with(['approve again' => 'POST', 'deny after approving' => 'DELETE']);

test('a guest is sent to the login page first', function () {
    $clientId = registerOAuthClient();

    get('/oauth/authorize?'.http_build_query(authorizationQuery($clientId, Str::random(64))))->assertRedirect('/login');
});

test('a learner gets the message page with 403 and no code is issued', function () {
    $clientId = registerOAuthClient();

    actingAs(User::factory()->learner()->create())
        ->get('/oauth/authorize?'.http_build_query(authorizationQuery($clientId, Str::random(64))))
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->component('mcp/message')
            ->where('message', 'Only educators can connect an AI assistant.'));

    expect(DB::table('oauth_auth_codes')->count())->toBe(0);
});

test('a learner cannot approve or deny either', function (string $method) {
    actingAs(User::factory()->learner()->create())
        ->call($method, '/oauth/authorize', ['auth_token' => 'anything'])
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->component('mcp/message'));

    expect(DB::table('oauth_auth_codes')->count())->toBe(0)
        ->and(DB::table('oauth_access_tokens')->count())->toBe(0);
})->with(['POST', 'DELETE']);

test('an Inertia login ends with a full page visit to the authorization request', function () {
    $clientId = registerOAuthClient();
    $educator = User::factory()->educator()->create();
    $query = authorizationQuery($clientId, Str::random(64));

    get('/oauth/authorize?'.http_build_query($query))->assertRedirect('/login');

    $response = post('/login', ['email' => $educator->email, 'password' => 'password'], ['X-Inertia' => 'true'])
        ->assertStatus(409);

    $location = (string) $response->headers->get('X-Inertia-Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $intended);

    expect($location)->toStartWith('http://localhost/oauth/authorize?')
        ->and($intended)->toEqual($query);
});

test('with a live token for the client, the authorization request redirects to the client without consent', function () {
    $clientId = registerOAuthClient();
    $verifier = Str::random(64);
    $educator = User::factory()->educator()->create();

    $consent = actingAs($educator)->get('/oauth/authorize?'.http_build_query(authorizationQuery($clientId, $verifier)));
    $code = callbackQuery(post('/oauth/authorize', ['auth_token' => $consent->inertiaProps('authToken')]), 'http://localhost:9999/callback')['code'];
    post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $clientId,
        'redirect_uri' => 'http://localhost:9999/callback',
        'code_verifier' => $verifier,
        'code' => $code,
    ])->assertOk();

    $again = get('/oauth/authorize?'.http_build_query(authorizationQuery($clientId, Str::random(64))));

    expect(callbackQuery($again, 'http://localhost:9999/callback'))->toHaveKeys(['code', 'state']);
});
