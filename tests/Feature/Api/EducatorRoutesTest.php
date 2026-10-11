<?php

use App\Models\AuditEntry;
use App\Models\ContentItem;
use App\Models\Group;
use App\Models\Institution;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;
use function Pest\Laravel\json;

dataset('educator routes', [
    'GET /groups' => ['GET', '/api/v1/groups'],
    'POST /groups' => ['POST', '/api/v1/groups'],
    'GET /groups/{group}' => ['GET', '/api/v1/groups/{group}'],
    'POST /groups/{group}/learners' => ['POST', '/api/v1/groups/{group}/learners'],
    'DELETE /groups/{group}/learners/{user}' => ['DELETE', '/api/v1/groups/{group}/learners/{user}'],
    'GET /learners' => ['GET', '/api/v1/learners'],
    'GET /content' => ['GET', '/api/v1/content'],
]);

dataset('group routes', [
    'GET /groups/{group}' => ['GET', '/api/v1/groups/{group}'],
    'POST /groups/{group}/learners' => ['POST', '/api/v1/groups/{group}/learners'],
    'DELETE /groups/{group}/learners/{user}' => ['DELETE', '/api/v1/groups/{group}/learners/{user}'],
]);

test('a learner\'s token answers 403 on every educator route', function (string $method, string $uri) {
    $group = Group::factory()->create();
    educatorOf($group);
    $learner = actingAsLearner(learnerIn($group));

    json($method, str_replace(['{group}', '{user}'], [(string) $group->id, (string) $learner->id], $uri), ['name' => 'Group', 'user_ids' => [$learner->id]])
        ->assertForbidden()
        ->assertExactJson(['error' => ['code' => 'forbidden', 'message' => 'Forbidden.']]);

    expect(Group::query()->count())->toBe(1)
        ->and(AuditEntry::query()->count())->toBe(0);
})->with('educator routes');

test('another educator\'s group and a missing id answer the same 404 and change nothing', function (string $method, string $uri) {
    $group = Group::factory()->create();
    educatorOf($group);
    $member = learnerIn($group);
    $outsider = User::factory()->learner()->create(['institution_id' => $group->institution_id]);
    $missingId = $group->id + 1000;
    $userId = $method === 'DELETE' ? $member->id : $outsider->id;

    actingAsEducator(User::factory()->educator()->create(['institution_id' => $group->institution_id]));

    $foreign = json($method, str_replace(['{group}', '{user}'], [(string) $group->id, (string) $userId], $uri), ['user_ids' => [$outsider->id]]);
    $missing = json($method, str_replace(['{group}', '{user}'], [(string) $missingId, (string) $userId], $uri), ['user_ids' => [$outsider->id]]);

    $foreign->assertNotFound()->assertExactJson(['error' => ['code' => 'not_found', 'message' => 'Group not found.']]);

    expect($missing->status())->toBe(404)
        ->and($missing->getContent())->toBe($foreign->getContent())
        ->and(DB::table('group_learners')->where('group_id', $group->id)->pluck('user_id')->all())->toBe([$member->id])
        ->and(AuditEntry::query()->count())->toBe(0);
})->with('group routes');

test('a non-numeric id answers 404 Not found.', function (string $method, string $uri) {
    actingAsEducator();

    json($method, str_replace(['{group}', '{user}'], ['abc', 'abc'], $uri))
        ->assertNotFound()
        ->assertExactJson(['error' => ['code' => 'not_found', 'message' => 'Not found.']]);
})->with('group routes');

test('no response carries an email except GET /learners', function () {
    $group = Group::factory()->create();
    $educator = educatorOf($group);
    $member = learnerIn($group);
    $newcomer = User::factory()->learner()->create(['institution_id' => $group->institution_id]);
    ContentItem::factory()->create();

    actingAsEducator($educator);

    $responses = [
        getJson('/api/v1/groups')->assertOk(),
        json('POST', '/api/v1/groups', ['name' => 'Cardiology Group B'])->assertCreated(),
        getJson("/api/v1/groups/{$group->id}")->assertOk(),
        json('POST', "/api/v1/groups/{$group->id}/learners", ['user_ids' => [$member->id, $newcomer->id]])->assertOk(),
        getJson('/api/v1/content')->assertOk(),
    ];

    foreach ($responses as $response) {
        $keys = collect(Arr::dot($response->json()))->keys()->flatMap(fn (string $key) => explode('.', $key));

        expect($keys)->not->toContain('email');
    }

    json('DELETE', "/api/v1/groups/{$group->id}/learners/{$newcomer->id}")->assertNoContent();

    expect(getJson('/api/v1/learners')->assertOk()->json('data.*.email'))
        ->toEqualCanonicalizing([$member->email, $newcomer->email]);
});

test('every date-time carries the institution\'s offset for its date', function () {
    $institution = Institution::factory()->create(['timezone' => 'Europe/Berlin']);
    $educator = User::factory()->educator()->create(['institution_id' => $institution->id]);
    $summer = Group::factory()->create(['institution_id' => $institution->id, 'name' => 'Summer', 'created_at' => '2026-07-01 10:00:00']);
    $winter = Group::factory()->create(['institution_id' => $institution->id, 'name' => 'Winter', 'created_at' => '2026-01-15 10:00:00']);
    $summer->educators()->attach($educator);
    $winter->educators()->attach($educator);
    CarbonImmutable::setTestNow('2026-12-01 09:30:00');

    actingAsEducator($educator);

    expect(getJson('/api/v1/groups')->assertOk()->json('data.*.created_at'))
        ->toBe(['2026-07-01T12:00:00+02:00', '2026-01-15T11:00:00+01:00'])
        ->and(getJson("/api/v1/groups/{$summer->id}")->assertOk()->json('data.created_at'))->toBe('2026-07-01T12:00:00+02:00')
        ->and(getJson("/api/v1/groups/{$winter->id}")->assertOk()->json('data.created_at'))->toBe('2026-01-15T11:00:00+01:00')
        ->and(json('POST', '/api/v1/groups', ['name' => 'December'])->assertCreated()->json('data.created_at'))->toBe('2026-12-01T10:30:00+01:00');
});
