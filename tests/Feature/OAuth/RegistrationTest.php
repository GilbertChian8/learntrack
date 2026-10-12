<?php

use Illuminate\Support\Facades\DB;

use function Pest\Laravel\postJson;

test('registration creates a public client', function () {
    $response = postJson('/oauth/register', [
        'client_name' => 'Claude Code',
        'redirect_uris' => ['http://localhost:9999/callback'],
    ]);

    $response->assertCreated()
        ->assertJsonPath('token_endpoint_auth_method', 'none')
        ->assertJsonPath('redirect_uris', ['http://localhost:9999/callback'])
        ->assertJsonPath('grant_types', ['authorization_code', 'refresh_token']);

    $client = DB::table('oauth_clients')->where('id', $response->json('client_id'))->first();

    expect($client->name)->toBe('Claude Code')
        ->and($client->secret)->toBeNull();
});

test('Claude and local redirect URIs are accepted', function (string $redirectUri) {
    postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [$redirectUri]])->assertCreated();
})->with([
    'Claude' => 'https://claude.ai/api/mcp/auth_callback',
    'Claude on claude.com' => 'https://claude.com/api/mcp/auth_callback',
    'localhost, any port' => 'http://localhost:53682/callback',
    '127.0.0.1, any port' => 'http://127.0.0.1:6274/oauth/callback',
]);

test('any other redirect URI is refused and no client is created', function (string $redirectUri) {
    postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [$redirectUri]])
        ->assertBadRequest()
        ->assertJsonPath('error', 'invalid_redirect_uri');

    expect(DB::table('oauth_clients')->count())->toBe(0);
})->with([
    'another site' => 'https://evil.example/callback',
    'a look-alike host' => 'https://claude.ai.evil.example/callback',
    'https on localhost' => 'https://localhost:9999/callback',
    'a custom scheme' => 'claude://callback',
]);
