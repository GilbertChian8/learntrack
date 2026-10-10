<?php

use App\Models\Assignment;
use App\Models\Group;
use App\Models\User;
use App\Policies\AssignmentPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\getJson;

test('manage allows an active assignment in a group the educator teaches', function () {
    $group = Group::factory()->create();
    $assignment = Assignment::factory()->for($group)->create();

    expect((new AssignmentPolicy)->manage(educatorOf($group), $assignment)->allowed())->toBeTrue();
});

test('manage follows the group: another educator of the same institution is denied as not found', function () {
    $group = Group::factory()->create();
    educatorOf($group);
    $assignment = Assignment::factory()->for($group)->create();
    $otherEducator = User::factory()->educator()->create(['institution_id' => $group->institution_id]);

    $response = (new AssignmentPolicy)->manage($otherEducator, $assignment);

    expect($response->denied())->toBeTrue()
        ->and($response->status())->toBe(404);
});

test('manage denies a removed assignment', function () {
    $group = Group::factory()->create();
    $assignment = Assignment::factory()->for($group)->removed()->create();

    expect((new AssignmentPolicy)->manage(educatorOf($group), $assignment)->denied())->toBeTrue();
});

test('manageableBy lists only active assignments of the groups the educator teaches', function () {
    $group = Group::factory()->create();
    $educator = educatorOf($group);
    $active = Assignment::factory()->for($group)->create();
    Assignment::factory()->for($group)->removed()->create();
    Assignment::factory()->create();

    expect(AssignmentPolicy::manageableBy($educator)->pluck('id')->all())->toBe([$active->id]);
});

test('recordProgress allows a member when the assignment has scope group', function () {
    $group = Group::factory()->create();
    $member = learnerIn($group);
    $assignment = Assignment::factory()->for($group)->create();

    expect((new AssignmentPolicy)->recordProgress($member, $assignment)->allowed())->toBeTrue();
});

test('recordProgress allows a current member with an assignment_learners row', function () {
    $group = Group::factory()->create();
    $member = learnerIn($group);
    $assignment = Assignment::factory()->for($group)->forLearners([$member])->create();

    expect((new AssignmentPolicy)->recordProgress($member, $assignment)->allowed())->toBeTrue();
});

test('recordProgress denies as not found a member a subset assignment does not target', function () {
    $group = Group::factory()->create();
    $member = learnerIn($group);
    $assignment = Assignment::factory()->for($group)->forLearners([learnerIn($group)])->create();

    $response = (new AssignmentPolicy)->recordProgress($member, $assignment);

    expect($response->denied())->toBeTrue()
        ->and($response->status())->toBe(404);
});

test('recordProgress denies a learner who left the group, even with an assignment_learners row', function () {
    $group = Group::factory()->create();
    $member = learnerIn($group);
    $assignment = Assignment::factory()->for($group)->forLearners([$member])->create();
    $group->learners()->detach($member);

    expect((new AssignmentPolicy)->recordProgress($member, $assignment)->denied())->toBeTrue();
});

test('recordProgress denies a removed assignment', function () {
    $group = Group::factory()->create();
    $member = learnerIn($group);
    $assignment = Assignment::factory()->for($group)->removed()->create();

    expect((new AssignmentPolicy)->recordProgress($member, $assignment)->denied())->toBeTrue();
});

test('recordProgress denies a learner outside the group and the group\'s educator', function () {
    $group = Group::factory()->create();
    learnerIn($group);
    $assignment = Assignment::factory()->for($group)->create();
    $outsider = User::factory()->learner()->create(['institution_id' => $group->institution_id]);

    expect((new AssignmentPolicy)->recordProgress($outsider, $assignment)->denied())->toBeTrue()
        ->and((new AssignmentPolicy)->recordProgress(educatorOf($group), $assignment)->denied())->toBeTrue();
});

test('targeting lists only active assignments that target the learner', function () {
    $group = Group::factory()->create();
    $member = learnerIn($group);
    $groupWide = Assignment::factory()->for($group)->create();
    $subset = Assignment::factory()->for($group)->forLearners([$member])->create();
    Assignment::factory()->for($group)->forLearners([learnerIn($group)])->create();
    Assignment::factory()->for($group)->removed()->create();
    Assignment::factory()->create();

    expect(AssignmentPolicy::targeting($member)->pluck('id')->sort()->values()->all())
        ->toBe([$groupWide->id, $subset->id]);
});

test('Gate::authorize on a denied ability renders 404 not_found, not 403', function (string $ability, Closure $actAs) {
    Route::middleware(['api', 'auth:api'])->get('api/v1/test/assignments/{assignment}', function (Assignment $assignment) use ($ability) {
        Gate::authorize($ability, $assignment);

        return response()->noContent();
    });
    $group = Group::factory()->create();
    educatorOf($group);
    learnerIn($group);
    $assignment = Assignment::factory()->for($group)->create();

    $actAs($group);

    getJson("/api/v1/test/assignments/{$assignment->id}")
        ->assertNotFound()
        ->assertExactJson(['error' => ['code' => 'not_found', 'message' => 'Not found.']]);
})->with([
    'manage, another educator' => ['manage', fn (Group $group) => actingAsEducator(User::factory()->educator()->create(['institution_id' => $group->institution_id]))],
    'recordProgress, a learner outside the group' => ['recordProgress', fn (Group $group) => actingAsLearner(User::factory()->learner()->create(['institution_id' => $group->institution_id]))],
]);
