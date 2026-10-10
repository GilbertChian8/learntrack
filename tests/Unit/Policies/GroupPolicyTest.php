<?php

use App\Models\Group;
use App\Models\User;
use App\Policies\GroupPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\getJson;

test('view allows an educator in group_educators', function () {
    $group = Group::factory()->create();

    expect((new GroupPolicy)->view(educatorOf($group), $group)->allowed())->toBeTrue();
});

test('view denies any other user as not found', function (Closure $makeUser) {
    $group = Group::factory()->create();
    educatorOf($group);

    $response = (new GroupPolicy)->view($makeUser($group), $group);

    expect($response->denied())->toBeTrue()
        ->and($response->status())->toBe(404);
})->with([
    'an educator of the same institution' => fn (Group $group) => User::factory()->educator()->create(['institution_id' => $group->institution_id]),
    'an educator of another institution' => fn (Group $group) => User::factory()->educator()->create(),
    'a learner of the group' => fn (Group $group) => learnerIn($group),
]);

test('taughtBy lists only the groups the educator teaches', function () {
    $group = Group::factory()->create();
    $educator = educatorOf($group);
    $second = Group::factory()->create(['institution_id' => $group->institution_id]);
    $second->educators()->attach($educator);
    Group::factory()->create(['institution_id' => $group->institution_id]);

    expect(GroupPolicy::taughtBy($educator)->pluck('id')->sort()->values()->all())
        ->toBe([$group->id, $second->id]);
});

test('Gate::authorize on a denied view renders 404 not_found, not 403', function () {
    Route::middleware(['api', 'auth:api'])->get('api/v1/test/groups/{group}', function (Group $group) {
        Gate::authorize('view', $group);

        return response()->noContent();
    });
    $group = Group::factory()->create();
    educatorOf($group);

    actingAsEducator(User::factory()->educator()->create(['institution_id' => $group->institution_id]));

    getJson("/api/v1/test/groups/{$group->id}")
        ->assertNotFound()
        ->assertExactJson(['error' => ['code' => 'not_found', 'message' => 'Not found.']]);
});
