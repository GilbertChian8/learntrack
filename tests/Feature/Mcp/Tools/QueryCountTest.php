<?php

use App\Models\Group;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-09 12:20:00');
});

$queriesOf = function (User $educator, string $tool, array $arguments = []): int {
    DB::flushQueryLog();
    DB::enableQueryLog();
    $result = callTool($educator, $tool, $arguments);
    DB::disableQueryLog();

    expect($result['isError'])->toBeFalse();

    return count(DB::getQueryLog());
};

test('each read tool runs a fixed number of queries whatever the group size', function (int $learnerCount, int $assignmentCount) use ($queriesOf) {
    $group = Group::factory()->create();
    $educator = educatorOf($group);
    seedLargeGroup($group, $educator, $learnerCount, $assignmentCount);
    $learner = User::query()->where('name', 'Learner 7')->sole();
    $learner->update(['name' => 'Sara Lindqvist']);

    expect($queriesOf($educator, 'list_my_groups'))->toBe(1)
        ->and($queriesOf($educator, 'get_group_progress', ['group_id' => $group->id]))->toBe(2)
        ->and($queriesOf($educator, 'find_learners_behind', ['group_id' => $group->id]))->toBe(2)
        ->and($queriesOf($educator, 'find_hardest_assignments', ['group_id' => $group->id, 'limit' => 20]))->toBe(2)
        ->and($queriesOf($educator, 'get_learner_summary', ['learner_id' => $learner->id]))->toBe(2)
        ->and($queriesOf($educator, 'get_learner_summary', ['name' => 'Lindqvist']))->toBe(3);
})->with([
    '60 learners and 100 assignments' => [60, 100],
    '120 learners and 200 assignments' => [120, 200],
]);

test('an ambiguous name is one query, the candidates query', function () use ($queriesOf) {
    $group = Group::factory()->create();
    $educator = educatorOf($group);
    seedLargeGroup($group, $educator, 60, 100);

    expect($queriesOf($educator, 'get_learner_summary', ['name' => 'Learner']))->toBe(1);
});
