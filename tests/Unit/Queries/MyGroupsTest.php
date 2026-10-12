<?php

use App\Models\Assignment;
use App\Models\Group;
use App\Models\User;
use App\Queries\MyGroups;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;
use function Pest\Laravel\withoutMiddleware;

test('MyGroups and GET /groups run one query whatever the number of groups', function (int $groupCount) {
    withoutMiddleware(ThrottleRequests::class);
    $educator = User::factory()->educator()->create();
    $groups = Group::factory()->count($groupCount)->create(['institution_id' => $educator->institution_id]);
    foreach ($groups as $group) {
        $group->educators()->attach($educator);
        learnerIn($group);
        Assignment::factory()->for($group)->create();
    }

    DB::enableQueryLog();
    DB::flushQueryLog();

    $myGroups = MyGroups::for($educator)->get();

    expect($myGroups)->toHaveCount($groupCount)
        ->and($myGroups->every(fn (Group $group) => $group->institution->id === $educator->institution_id
            && $group->getAttribute('learner_count') === 1
            && $group->getAttribute('assignment_count') === 1))->toBeTrue()
        ->and(DB::getQueryLog())->toHaveCount(1);

    actingAsEducator($educator);
    DB::flushQueryLog();

    getJson('/api/v1/groups?limit=200')->assertOk()->assertJsonCount($groupCount, 'data');

    expect(DB::getQueryLog())->toHaveCount(1);
})->with([3, 30]);
