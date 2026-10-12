<?php

use Illuminate\Foundation\Vite;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeaders;

dataset('responses', [
    'the login page' => fn (): TestResponse => get('/login')->assertOk(),
    'a JSON answer' => function (): TestResponse {
        actingAsEducator();

        return getJson('/api/v1/me')->assertOk();
    },
    '401' => fn (): TestResponse => getJson('/api/v1/me')->assertUnauthorized(),
    '404 on the API' => fn (): TestResponse => getJson('/api/v1/no-such-route')->assertNotFound(),
    '404 on a web page' => fn (): TestResponse => get('/no-such-page')->assertNotFound(),
    '429' => function (): TestResponse {
        foreach (range(1, 10) as $request) {
            postJson('/oauth/register', ['redirect_uris' => []]);
        }

        return postJson('/oauth/register', ['redirect_uris' => []])->assertTooManyRequests();
    },
    'the health check' => fn (): TestResponse => get('/up')->assertOk(),
]);

test('every response carries the four fixed security headers, and no HSTS over http', function (Closure $request) {
    $request()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Content-Security-Policy')
        ->assertHeaderMissing('Strict-Transport-Security');
})->with('responses');

test('HSTS is sent on https', function () {
    get('https://localhost/up')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

test('HSTS is sent when a trusted proxy says the request came over https', function () {
    config(['trustedproxy.proxies' => '10.0.0.0/16']);

    withHeaders(['X-Forwarded-Proto' => 'https'])
        ->withServerVariables(['REMOTE_ADDR' => '10.0.12.34'])
        ->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

test('X-Forwarded-Proto from an untrusted address does not turn on HSTS', function () {
    config(['trustedproxy.proxies' => '10.0.0.0/16']);

    withHeaders(['X-Forwarded-Proto' => 'https'])
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->get('/up')
        ->assertHeaderMissing('Strict-Transport-Security');
});

test('the policy allows only this origin, plus scripts and styles that carry the nonce of the response', function () {
    $response = get('/login')->assertOk();

    $policy = (string) $response->headers->get('Content-Security-Policy');

    expect(preg_match("/'nonce-([A-Za-z0-9]+)'/", $policy, $match))->toBe(1);

    $nonce = $match[1];

    expect($policy)->toBe(implode('; ', [
        "default-src 'self'",
        "script-src 'self' 'nonce-{$nonce}'",
        "style-src 'self' 'nonce-{$nonce}'",
        "object-src 'none'",
        "base-uri 'self'",
        "frame-ancestors 'none'",
    ]));

    $html = (string) $response->getContent();

    preg_match_all('/<(?:script|style)\b[^>]*>/', $html, $tags);

    $runnable = collect($tags[0])->reject(fn (string $tag) => str_contains($tag, 'type="application/json"'));

    expect($runnable)->not->toBeEmpty()
        ->and($runnable->reject(fn (string $tag) => str_contains($tag, "nonce=\"{$nonce}\""))->all())->toBe([])
        ->and($html)->toContain("<meta property=\"csp-nonce\" nonce=\"{$nonce}\">");
});

test('each response gets a new nonce', function () {
    $nonce = fn (): string => (string) preg_replace("/.*'nonce-([A-Za-z0-9]+)'.*/", '$1', (string) get('/login')->headers->get('Content-Security-Policy'));

    expect($nonce())->not->toBe($nonce());
});

test('in local, the policy also allows the running Vite dev server', function () {
    $hotFile = (string) tempnam(sys_get_temp_dir(), 'hot');
    file_put_contents($hotFile, "http://localhost:5173\n");
    app()->instance(Vite::class, (new Vite)->useHotFile($hotFile));
    app()->detectEnvironment(fn () => 'local');

    $policy = (string) get('/up')->headers->get('Content-Security-Policy');

    expect(explode('; ', (string) preg_replace("/'nonce-[A-Za-z0-9]+'/", "'nonce'", $policy)))->toBe([
        "default-src 'self' http://localhost:5173",
        "script-src 'self' http://localhost:5173 'nonce'",
        "style-src 'self' http://localhost:5173 'nonce'",
        "connect-src 'self' http://localhost:5173 ws://localhost:5173",
        "object-src 'none'",
        "base-uri 'self'",
        "frame-ancestors 'none'",
    ]);
});

test('outside local, a running Vite dev server is not allowed', function () {
    $hotFile = (string) tempnam(sys_get_temp_dir(), 'hot');
    file_put_contents($hotFile, 'http://localhost:5173');
    app()->instance(Vite::class, (new Vite)->useHotFile($hotFile));

    expect((string) get('/up')->headers->get('Content-Security-Policy'))->not->toContain('localhost:5173');
});
