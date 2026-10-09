<?php

use App\Models\Assignment;
use App\Models\Group;
use App\Models\User;
use App\Policies\AssignmentPolicy;

test('manage is true for an active assignment in a group the educator teaches', function () {
    $group = Group::factory()->create();
    $assignment = Assignment::factory()->for($group)->create();

    expect((new AssignmentPolicy)->manage(educatorOf($group), $assignment))->toBeTrue();
});

test('manage follows the group: false for another educator of the same institution', function () {
    $group = Group::factory()->create();
    educatorOf($group);
    $assignment = Assignment::factory()->for($group)->create();
    $otherEducator = User::factory()->educator()->create(['institution_id' => $group->institution_id]);

    expect((new AssignmentPolicy)->manage($otherEducator, $assignment))->toBeFalse();
});

test('manage is false for a removed assignment', function () {
    $group = Group::factory()->create();
    $assignment = Assignment::factory()->for($group)->removed()->create();

    expect((new AssignmentPolicy)->manage(educatorOf($group), $assignment))->toBeFalse();
});

test('manageableBy lists only active assignments of the groups the educator teaches', function () {
    $group = Group::factory()->create();
    $educator = educatorOf($group);
    $active = Assignment::factory()->for($group)->create();
    Assignment::factory()->for($group)->removed()->create();
    Assignment::factory()->create();

    expect(AssignmentPolicy::manageableBy($educator)->pluck('id')->all())->toBe([$active->id]);
});
