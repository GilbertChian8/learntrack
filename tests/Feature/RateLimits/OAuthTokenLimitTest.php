<?php

use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\travel;
use function Pest\Laravel\withServerVariables;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-12 09:00:00');
});

$token = fn (string $ip = '198.51.100.7'): TestResponse => withServerVariables(['REMOTE_ADDR' => $ip])
    ->postJson('/oauth/token', ['grant_type' => 'authorization_code']);

test('thirty requests a minute from one IP pass, the thirty-first answers 429 in the contract shape', function () use ($token) {
    foreach (range(1, 30) as $request) {
        $token()->assertBadRequest();
    }

    assertTooManyRequests($token(), 60);
});

test('another IP keeps its own budget', function () use ($token) {
    foreach (range(1, 30) as $request) {
        $token();
    }

    $token()->assertTooManyRequests();
    $token('203.0.113.9')->assertBadRequest();
});

test('a 429 does not use budget, and the budget comes back after a minute', function () use ($token) {
    foreach (range(1, 30) as $request) {
        $token();
    }

    foreach (range(1, 3) as $request) {
        $token()->assertTooManyRequests();
    }

    expect(rateLimitHits('oauth-token', '198.51.100.7'))->toBe(30);

    travel(60)->seconds();

    $token()->assertBadRequest();
});
