<?php

use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\postJson;
use function Pest\Laravel\travel;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-12 09:00:00');
});

$listTools = fn (): TestResponse => postJson('/mcp', mcpRequest('tools/list'));

test('sixty calls a minute pass, the sixty-first answers 429 in the contract shape', function () use ($listTools) {
    actingAsEducator();

    foreach (range(1, 60) as $call) {
        $listTools()->assertOk();
    }

    assertTooManyRequests($listTools(), 60);
});

test('two educators do not share a budget', function () use ($listTools) {
    $anna = actingAsEducator();

    foreach (range(1, 60) as $call) {
        $listTools();
    }

    $listTools()->assertTooManyRequests();

    actingAsEducator();
    $listTools()->assertOk();

    actingAsEducator($anna);
    $listTools()->assertTooManyRequests();
});

test('a 429 does not use budget, and the budget comes back after a minute', function () use ($listTools) {
    $educator = actingAsEducator();

    foreach (range(1, 60) as $call) {
        $listTools();
    }

    foreach (range(1, 3) as $call) {
        $listTools()->assertTooManyRequests();
    }

    expect(rateLimitHits('mcp', (string) $educator->id))->toBe(60);

    travel(60)->seconds();

    $listTools()->assertOk();
});
