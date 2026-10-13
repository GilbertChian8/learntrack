<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\travel;
use function Pest\Laravel\withServerVariables;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-12 09:00:00');
});

$register = fn (string $ip = '198.51.100.7'): TestResponse => withServerVariables(['REMOTE_ADDR' => $ip])
    ->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']]);

test('ten registrations an hour from one IP pass, the eleventh answers 429 in the contract shape', function () use ($register) {
    foreach (range(1, 10) as $request) {
        $register()->assertCreated();
    }

    assertTooManyRequests($register(), 3600);

    expect(DB::table('oauth_clients')->count())->toBe(10);
});

test('another IP keeps its own budget', function () use ($register) {
    foreach (range(1, 10) as $request) {
        $register();
    }

    $register()->assertTooManyRequests();
    $register('203.0.113.9')->assertCreated();
});

test('a 429 does not use budget, and the budget comes back after an hour', function () use ($register) {
    foreach (range(1, 10) as $request) {
        $register();
    }

    foreach (range(1, 3) as $request) {
        $register()->assertTooManyRequests();
    }

    expect(rateLimitHits('oauth-register', '198.51.100.7'))->toBe(10);

    travel(59)->minutes();
    $register()->assertTooManyRequests();

    travel(1)->minutes();
    $register()->assertCreated();
});
