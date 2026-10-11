<?php

use App\Models\Institution;
use App\Models\User;

use function Pest\Laravel\getJson;

test('lists only the learners of the caller\'s institution with their email, ordered by name then id', function () {
    $institution = Institution::factory()->create();
    $educator = User::factory()->educator()->create(['institution_id' => $institution->id, 'name' => 'Anna Keller']);
    $tom = User::factory()->learner()->create(['institution_id' => $institution->id, 'name' => 'Tom Becker', 'email' => 'tom.becker@example.edu']);
    $sara = User::factory()->learner()->create(['institution_id' => $institution->id, 'name' => 'Sara Lindqvist', 'email' => 'sara.lindqvist@example.edu']);
    $secondTom = User::factory()->learner()->create(['institution_id' => $institution->id, 'name' => 'Tom Becker', 'email' => 'tom.becker2@example.edu']);
    User::factory()->learner()->create(['name' => 'Emily Carter']);
    User::factory()->educator()->create(['institution_id' => $institution->id, 'name' => 'Jonas Weber']);

    actingAsEducator($educator);

    getJson('/api/v1/learners')
        ->assertOk()
        ->assertExactJson([
            'data' => [
                ['id' => $sara->id, 'name' => 'Sara Lindqvist', 'email' => 'sara.lindqvist@example.edu'],
                ['id' => $tom->id, 'name' => 'Tom Becker', 'email' => 'tom.becker@example.edu'],
                ['id' => $secondTom->id, 'name' => 'Tom Becker', 'email' => 'tom.becker2@example.edu'],
            ],
            'meta' => ['next_cursor' => null],
        ]);
});

test('search matches part of a name or an email, case-insensitive, anywhere in the string', function (string $search, array $names) {
    $institution = Institution::factory()->create();
    $educator = User::factory()->educator()->create(['institution_id' => $institution->id]);
    User::factory()->learner()->create(['institution_id' => $institution->id, 'name' => 'Tom Becker', 'email' => 'tom.becker@example.edu']);
    User::factory()->learner()->create(['institution_id' => $institution->id, 'name' => 'Sara Lindqvist', 'email' => 'sara.l@example.edu']);
    User::factory()->learner()->create(['name' => 'Sara Becker', 'email' => 'sara.becker@example.org']);

    actingAsEducator($educator);

    expect(getJson('/api/v1/learners?'.http_build_query(['search' => $search]))->assertOk()->json('data.*.name'))->toBe($names);
})->with([
    'part of a name in another case' => ['NDQV', ['Sara Lindqvist']],
    'part of an email' => ['becker@', ['Tom Becker']],
    'a wildcard character is literal' => ['%', []],
]);

test('pages with limit and cursor', function () {
    $institution = Institution::factory()->create();
    $educator = User::factory()->educator()->create(['institution_id' => $institution->id]);
    $first = User::factory()->learner()->create(['institution_id' => $institution->id, 'name' => 'Anna Adams']);
    $second = User::factory()->learner()->create(['institution_id' => $institution->id, 'name' => 'Ben Brown']);

    actingAsEducator($educator);

    $firstPage = getJson('/api/v1/learners?limit=1')->assertOk()->assertJsonPath('data.0.id', $first->id);

    getJson('/api/v1/learners?'.http_build_query(['limit' => 1, 'cursor' => $firstPage->json('meta.next_cursor')]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $second->id)
        ->assertJsonPath('meta.next_cursor', null);
});
