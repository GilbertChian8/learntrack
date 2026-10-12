<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\post;

beforeEach(function () {
    $client = Client::factory()->asPublic()->create();
    $user = User::factory()->educator()->create();

    $token = fn (string $id, bool $revoked, string $expiresAt) => [
        'id' => $id,
        'user_id' => $user->id,
        'client_id' => $client->id,
        'scopes' => '[]',
        'revoked' => $revoked,
        'created_at' => now(),
        'updated_at' => now(),
        'expires_at' => $expiresAt,
    ];

    DB::table('oauth_access_tokens')->insert([
        $token('live-token', false, now()->addHour()),
        $token('revoked-token', true, now()->addHour()),
        $token('expired-token', false, now()->subMinute()),
    ]);

    DB::table('oauth_refresh_tokens')->insert([
        ['id' => 'live-refresh', 'access_token_id' => 'live-token', 'revoked' => false, 'expires_at' => now()->addDays(30)],
        ['id' => 'revoked-refresh', 'access_token_id' => 'revoked-token', 'revoked' => true, 'expires_at' => now()->addDays(30)],
        ['id' => 'expired-refresh', 'access_token_id' => 'expired-token', 'revoked' => false, 'expires_at' => now()->subMinute()],
    ]);

    DB::table('oauth_auth_codes')->insert([
        ['id' => 'live-code', 'user_id' => $user->id, 'client_id' => $client->id, 'scopes' => '[]', 'revoked' => false, 'expires_at' => now()->addMinutes(10)],
        ['id' => 'used-code', 'user_id' => $user->id, 'client_id' => $client->id, 'scopes' => '[]', 'revoked' => true, 'expires_at' => now()->addMinutes(10)],
        ['id' => 'expired-code', 'user_id' => $user->id, 'client_id' => $client->id, 'scopes' => '[]', 'revoked' => false, 'expires_at' => now()->subMinute()],
    ]);

    DB::table('cache')->insert([
        ['key' => 'live', 'value' => 's:1:"a";', 'expiration' => now()->addMinute()->getTimestamp()],
        ['key' => 'expired', 'value' => 's:1:"b";', 'expiration' => now()->subSecond()->getTimestamp()],
    ]);

    DB::table('cache_locks')->insert([
        ['key' => 'live', 'owner' => 'a', 'expiration' => now()->addMinute()->getTimestamp()],
        ['key' => 'expired', 'owner' => 'b', 'expiration' => now()->subSecond()->getTimestamp()],
    ]);

    $session = fn (string $id, int $lastActivity) => [
        'id' => $id,
        'user_id' => null,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => base64_encode(serialize([])),
        'last_activity' => $lastActivity,
    ];

    DB::table('sessions')->insert([
        $session('live-session', now()->subMinutes(config('session.lifetime') - 1)->getTimestamp()),
        $session('old-session', now()->subMinutes(config('session.lifetime') + 1)->getTimestamp()),
    ]);
});

test('housekeeping removes expired and revoked tokens, expired cache rows and old sessions', function () {
    artisan('app:housekeeping')->assertSuccessful();

    expect(DB::table('oauth_access_tokens')->pluck('id')->all())->toBe(['live-token'])
        ->and(DB::table('oauth_refresh_tokens')->pluck('id')->all())->toBe(['live-refresh'])
        ->and(DB::table('oauth_auth_codes')->pluck('id')->all())->toBe(['live-code'])
        ->and(DB::table('cache')->pluck('key')->all())->toBe(['live'])
        ->and(DB::table('cache_locks')->pluck('key')->all())->toBe(['live'])
        ->and(DB::table('sessions')->pluck('id')->all())->toBe(['live-session']);
});

test('a second run changes nothing', function () {
    artisan('app:housekeeping')->assertSuccessful();

    $tables = ['oauth_access_tokens', 'oauth_refresh_tokens', 'oauth_auth_codes', 'cache', 'cache_locks', 'sessions'];
    $before = collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->get()->toArray()])->all();

    artisan('app:housekeeping')->assertSuccessful();

    expect(collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->get()->toArray()])->all())->toEqual($before);
});

test('a failure exits non-zero', function () {
    Exceptions::fake();
    config(['session.table' => 'no_such_table']);
    app('session')->forgetDrivers();

    $exitCode = app(Kernel::class)->handle(new ArrayInput(['command' => 'app:housekeeping']), new BufferedOutput);

    expect($exitCode)->toBe(1);
    Exceptions::assertReported(QueryException::class);
});

test('a refresh token still works after housekeeping removed its expired access token', function () {
    $clientId = registerOAuthClient();
    $verifier = Str::random(64);

    $consent = actingAs(User::factory()->educator()->create())->get('/oauth/authorize?'.http_build_query(authorizationQuery($clientId, $verifier)));
    $code = callbackQuery(post('/oauth/authorize', ['auth_token' => $consent->inertiaProps('authToken')]), 'http://localhost:9999/callback')['code'];
    $tokens = post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $clientId,
        'redirect_uri' => 'http://localhost:9999/callback',
        'code_verifier' => $verifier,
        'code' => $code,
    ])->json();

    DB::table('oauth_access_tokens')->where('client_id', $clientId)->update(['expires_at' => now()->subMinute()]);
    artisan('app:housekeeping')->assertSuccessful();
    expect(DB::table('oauth_access_tokens')->where('client_id', $clientId)->exists())->toBeFalse();

    post('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $clientId,
        'refresh_token' => $tokens['refresh_token'],
    ])->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);
});
