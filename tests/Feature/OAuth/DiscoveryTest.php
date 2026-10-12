<?php

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withHeaders;

test('the protected resource document names /mcp and this host as its authorization server', function () {
    getJson('/.well-known/oauth-protected-resource/mcp')
        ->assertOk()
        ->assertExactJson([
            'resource' => 'http://localhost/mcp',
            'authorization_servers' => ['http://localhost'],
            'scopes_supported' => ['mcp:use'],
        ]);
});

test('the root protected resource document names this host', function () {
    getJson('/.well-known/oauth-protected-resource')
        ->assertOk()
        ->assertJsonPath('resource', 'http://localhost')
        ->assertJsonPath('authorization_servers', ['http://localhost']);
});

test('the authorization server document lists the endpoints and S256', function () {
    getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonPath('issuer', 'http://localhost')
        ->assertJsonPath('authorization_endpoint', 'http://localhost/oauth/authorize')
        ->assertJsonPath('token_endpoint', 'http://localhost/oauth/token')
        ->assertJsonPath('registration_endpoint', 'http://localhost/oauth/register')
        ->assertJsonPath('code_challenge_methods_supported', ['S256'])
        ->assertJsonPath('grant_types_supported', ['authorization_code', 'refresh_token']);
});

test('X-Forwarded-Proto is trusted only from a trusted proxy', function (string $remoteAddress, string $origin, string $path) {
    config(['trustedproxy.proxies' => '10.0.0.0/16']);

    $urls = withHeaders(['X-Forwarded-Proto' => 'https'])
        ->withServerVariables(['REMOTE_ADDR' => $remoteAddress])
        ->get($path)
        ->assertOk()
        ->collect()
        ->flatten()
        ->filter(fn (mixed $value) => is_string($value) && str_contains($value, '://'));

    expect($urls)->not->toBeEmpty()
        ->and($urls->reject(fn (string $url) => str_starts_with($url, $origin))->all())->toBe([]);
})->with([
    'trusted proxy' => ['10.0.12.34', 'https://localhost'],
    'untrusted address' => ['203.0.113.9', 'http://localhost'],
])->with([
    '/.well-known/oauth-protected-resource',
    '/.well-known/oauth-protected-resource/mcp',
    '/.well-known/oauth-authorization-server',
]);

test('the documents name APP_URL, whatever Host or X-Forwarded headers the request carries', function (string $url, array $headers, string $remoteAddress) {
    config(['trustedproxy.proxies' => '10.0.0.0/16']);

    $hosts = withHeaders($headers)
        ->withServerVariables(['REMOTE_ADDR' => $remoteAddress])
        ->get($url)
        ->assertOk()
        ->collect()
        ->flatten()
        ->filter(fn (mixed $value) => is_string($value) && str_contains($value, '://'))
        ->map(fn (string $value) => parse_url($value, PHP_URL_HOST))
        ->unique()
        ->values()
        ->all();

    expect($hosts)->toBe(['localhost']);
})->with([
    'a forged Host' => ['http://evil.example/.well-known/oauth-authorization-server', [], '203.0.113.9'],
    'a forged Host on the resource document' => ['http://evil.example/.well-known/oauth-protected-resource/mcp', [], '203.0.113.9'],
    'X-Forwarded-Host from a trusted proxy' => ['/.well-known/oauth-authorization-server', ['X-Forwarded-Host' => 'evil.example'], '10.0.12.34'],
    'X-Forwarded-Host from an untrusted address' => ['/.well-known/oauth-authorization-server', ['X-Forwarded-Host' => 'evil.example'], '203.0.113.9'],
]);

test('the discovery documents need no session', function () {
    get('/.well-known/oauth-authorization-server')->assertOk()->assertCookieMissing(config('session.cookie'));
});
