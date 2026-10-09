<?php

use Illuminate\Support\Facades\Route;

use function Pest\Laravel\getJson;

beforeEach(function () {
    Route::middleware(['api', 'auth:api', 'role:educator'])->get('api/v1/test/educator-only', fn () => response()->noContent());
    Route::middleware(['api', 'auth:api', 'role:learner'])->get('api/v1/test/learner-only', fn () => response()->noContent());
});

test('a learner behind role:educator answers 403 forbidden', function () {
    actingAsLearner();

    getJson('/api/v1/test/educator-only')
        ->assertForbidden()
        ->assertExactJson(['error' => ['code' => 'forbidden', 'message' => 'Forbidden.']]);
});

test('an educator behind role:learner answers 403 forbidden', function () {
    actingAsEducator();

    getJson('/api/v1/test/learner-only')
        ->assertForbidden()
        ->assertExactJson(['error' => ['code' => 'forbidden', 'message' => 'Forbidden.']]);
});

test('the matching role passes', function () {
    actingAsEducator();
    getJson('/api/v1/test/educator-only')->assertNoContent();

    actingAsLearner();
    getJson('/api/v1/test/learner-only')->assertNoContent();
});
