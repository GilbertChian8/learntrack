<?php

use App\Models\Assignment;
use App\Models\Group;
use App\Models\User;
use App\Policies\ProgressPolicy;

test('update is true for a member when the assignment has scope group', function () {
    $group = Group::factory()->create();
    $member = learnerIn($group);
    $assignment = Assignment::factory()->for($group)->create();

    expect((new ProgressPolicy)->update($member, $assignment))->toBeTrue();
});

test('update is true for a current member with an assignment_learners row', function () {
    $group = Group::factory()->create();
    $member = learnerIn($group);
    $assignment = Assignment::factory()->for($group)->forLearners([$member])->create();

    expect((new ProgressPolicy)->update($member, $assignment))->toBeTrue();
});

test('update is false for a member a subset assignment does not target', function () {
    $group = Group::factory()->create();
    $member = learnerIn($group);
    $assignment = Assignment::factory()->for($group)->forLearners([learnerIn($group)])->create();

    expect((new ProgressPolicy)->update($member, $assignment))->toBeFalse();
});

test('update is false for a learner who left the group, even with an assignment_learners row', function () {
    $group = Group::factory()->create();
    $member = learnerIn($group);
    $assignment = Assignment::factory()->for($group)->forLearners([$member])->create();
    $group->learners()->detach($member);

    expect((new ProgressPolicy)->update($member, $assignment))->toBeFalse();
});

test('update is false for a removed assignment', function () {
    $group = Group::factory()->create();
    $member = learnerIn($group);
    $assignment = Assignment::factory()->for($group)->removed()->create();

    expect((new ProgressPolicy)->update($member, $assignment))->toBeFalse();
});

test('update is false for a learner outside the group and for the group\'s educator', function () {
    $group = Group::factory()->create();
    learnerIn($group);
    $assignment = Assignment::factory()->for($group)->create();
    $outsider = User::factory()->learner()->create(['institution_id' => $group->institution_id]);

    expect((new ProgressPolicy)->update($outsider, $assignment))->toBeFalse()
        ->and((new ProgressPolicy)->update(educatorOf($group), $assignment))->toBeFalse();
});

test('targeting lists only active assignments that target the learner', function () {
    $group = Group::factory()->create();
    $member = learnerIn($group);
    $groupWide = Assignment::factory()->for($group)->create();
    $subset = Assignment::factory()->for($group)->forLearners([$member])->create();
    Assignment::factory()->for($group)->forLearners([learnerIn($group)])->create();
    Assignment::factory()->for($group)->removed()->create();
    Assignment::factory()->create();

    expect(ProgressPolicy::targeting($member)->pluck('id')->sort()->values()->all())
        ->toBe([$groupWide->id, $subset->id]);
});
