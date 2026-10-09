<?php

use App\Models\Group;
use App\Models\User;
use App\Policies\GroupPolicy;

test('view is true for an educator in group_educators', function () {
    $group = Group::factory()->create();

    expect((new GroupPolicy)->view(educatorOf($group), $group))->toBeTrue();
});

test('view is false for any other user', function (Closure $makeUser) {
    $group = Group::factory()->create();
    educatorOf($group);

    expect((new GroupPolicy)->view($makeUser($group), $group))->toBeFalse();
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
