<?php

use App\Models\Assignment;
use App\Models\Group;
use App\Models\Institution;
use App\Models\User;

use function Pest\Laravel\getJson;

test('lists only the caller\'s groups, ordered by name then id, with learner and active assignment counts', function () {
    $institution = Institution::factory()->create(['name' => 'Northside Medical School']);
    $educator = User::factory()->educator()->create(['institution_id' => $institution->id]);
    $beta = Group::factory()->create(['institution_id' => $institution->id, 'name' => 'Beta', 'created_at' => '2026-09-01 07:00:00']);
    $alphaFirst = Group::factory()->create(['institution_id' => $institution->id, 'name' => 'Alpha', 'created_at' => '2026-09-02 07:00:00']);
    $alphaSecond = Group::factory()->create(['institution_id' => $institution->id, 'name' => 'Alpha', 'created_at' => '2026-09-03 07:00:00']);
    foreach ([$beta, $alphaFirst, $alphaSecond] as $group) {
        $group->educators()->attach($educator);
    }
    learnerIn($beta);
    learnerIn($beta);
    Assignment::factory()->for($beta)->count(2)->create();
    Assignment::factory()->for($beta)->removed()->create();
    $foreign = Group::factory()->create(['institution_id' => $institution->id, 'name' => 'Aaa']);
    educatorOf($foreign);
    learnerIn($foreign);

    actingAsEducator($educator);

    $summary = fn (Group $group, int $learners, int $assignments, string $createdAt) => [
        'id' => $group->id,
        'name' => $group->name,
        'institution' => ['id' => $institution->id, 'name' => 'Northside Medical School'],
        'learner_count' => $learners,
        'assignment_count' => $assignments,
        'created_at' => $createdAt,
    ];

    getJson('/api/v1/groups')
        ->assertOk()
        ->assertExactJson([
            'data' => [
                $summary($alphaFirst, 0, 0, '2026-09-02T07:00:00+00:00'),
                $summary($alphaSecond, 0, 0, '2026-09-03T07:00:00+00:00'),
                $summary($beta, 2, 2, '2026-09-01T07:00:00+00:00'),
            ],
            'meta' => ['next_cursor' => null],
        ]);
});

test('pages with limit and cursor', function () {
    $educator = actingAsEducator();
    $first = Group::factory()->create(['institution_id' => $educator->institution_id, 'name' => 'Alpha']);
    $second = Group::factory()->create(['institution_id' => $educator->institution_id, 'name' => 'Beta']);
    $first->educators()->attach($educator);
    $second->educators()->attach($educator);

    $firstPage = getJson('/api/v1/groups?limit=1')->assertOk();

    expect(array_keys($firstPage->json()))->toBe(['data', 'meta'])
        ->and(array_keys($firstPage->json('meta')))->toBe(['next_cursor'])
        ->and($firstPage->json('data'))->toHaveCount(1)
        ->and($firstPage->json('data.0.id'))->toBe($first->id)
        ->and($firstPage->json('meta.next_cursor'))->toBeString();

    getJson('/api/v1/groups?'.http_build_query(['limit' => 1, 'cursor' => $firstPage->json('meta.next_cursor')]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $second->id)
        ->assertJsonPath('meta.next_cursor', null);
});

test('a limit outside 1 to 200 answers 422', function (mixed $limit) {
    actingAsEducator();

    getJson('/api/v1/groups?'.http_build_query(['limit' => $limit]))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.message', 'The given data was invalid.')
        ->assertJsonValidationErrors(['limit'], 'error.details');
})->with([
    'limit 201' => [201],
    'limit 0' => [0],
    'limit not a number' => ['ten'],
]);

test('a malformed cursor or one from another list answers 422 with The cursor is invalid.', function (mixed $cursor) {
    $educator = actingAsEducator();
    Group::factory()->create(['institution_id' => $educator->institution_id])->educators()->attach($educator);

    getJson('/api/v1/groups?'.http_build_query(['cursor' => $cursor]))
        ->assertUnprocessable()
        ->assertExactJson([
            'error' => [
                'code' => 'validation_failed',
                'message' => 'The given data was invalid.',
                'details' => ['cursor' => ['The cursor is invalid.']],
            ],
        ]);
})->with([
    'garbage' => ['garbage'],
    'an array' => [['x']],
    'a cursor of another list' => [rtrim(base64_encode('{"users.id":5,"_pointsToNextItems":true}'), '=')],
    'a non-scalar value' => [rtrim(base64_encode('{"learner_groups.name":["x"],"learner_groups.id":1,"_pointsToNextItems":true}'), '=')],
]);

test('an empty limit or cursor means the default: 50, and the first page', function () {
    $educator = actingAsEducator();
    $groups = Group::factory()->count(51)->create(['institution_id' => $educator->institution_id]);
    foreach ($groups as $group) {
        $group->educators()->attach($educator);
    }

    $response = getJson('/api/v1/groups?limit=&cursor=')->assertOk()->assertJsonCount(50, 'data');

    expect($response->json('data.0.id'))->toBe(getJson('/api/v1/groups')->json('data.0.id'))
        ->and($response->json('meta.next_cursor'))->toBeString();
});
