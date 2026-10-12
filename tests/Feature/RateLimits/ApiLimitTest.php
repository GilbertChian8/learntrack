<?php

use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\travel;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-12 09:00:00');
});

$me = fn (): TestResponse => getJson('/api/v1/me');

$graphql = fn (): TestResponse => postJson('/graphql', ['query' => '{ __typename }']);

test('three hundred requests a minute pass, the next answers 429 in the contract shape', function () use ($me) {
    actingAsEducator();

    foreach (range(1, 300) as $request) {
        $me()->assertOk();
    }

    assertTooManyRequests($me(), 60);
});

test('REST and GraphQL share one budget', function () use ($me, $graphql) {
    actingAsEducator();

    foreach (range(1, 299) as $request) {
        $me()->assertOk();
    }

    $graphql()->assertOk()->assertExactJson(['data' => ['__typename' => 'Query']]);

    assertTooManyRequests($graphql(), 60);
    assertTooManyRequests($me(), 60);
});

test('two users do not share a budget', function () use ($me) {
    $educator = actingAsEducator();

    foreach (range(1, 300) as $request) {
        $me();
    }

    $me()->assertTooManyRequests();

    actingAsLearner();
    $me()->assertOk();

    actingAsEducator($educator);
    $me()->assertTooManyRequests();
});

test('a 429 does not use budget, and the budget comes back after a minute', function () use ($me) {
    $educator = actingAsEducator();

    foreach (range(1, 300) as $request) {
        $me();
    }

    foreach (range(1, 3) as $request) {
        $me()->assertTooManyRequests();
    }

    expect(rateLimitHits('api', (string) $educator->id))->toBe(300);

    travel(60)->seconds();

    $me()->assertOk();
});
