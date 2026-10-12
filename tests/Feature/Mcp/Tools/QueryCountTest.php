<?php

use App\Models\Group;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\withoutMiddleware;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-09 12:20:00');
});

/**
 * The SQL of the queries one tool call runs. Passport::actingAs authenticates without a query.
 *
 * @param  array<string, mixed>  $arguments
 * @return list<string>
 */
$queriesOf = function (User $educator, string $tool, array $arguments = []): array {
    withoutMiddleware(ThrottleRequests::class);
    actingAsEducator($educator);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $result = callTool($tool, $arguments)->json('result');
    DB::disableQueryLog();

    expect($result['isError'])->toBeFalse();

    return array_column(DB::getQueryLog(), 'query');
};

test('each read tool runs a fixed number of queries whatever the group size', function (int $learnerCount, int $assignmentCount) use ($queriesOf) {
    $group = Group::factory()->create();
    $educator = educatorOf($group);
    seedLargeGroup($group, $educator, $learnerCount, $assignmentCount);
    $learner = User::query()->where('name', 'Learner 7')->sole();
    $learner->update(['name' => 'Sara Lindqvist']);

    $byName = $queriesOf($educator, 'get_learner_summary', ['name' => 'Lindqvist']);

    expect($queriesOf($educator, 'list_my_groups'))->toHaveCount(1)
        ->and($queriesOf($educator, 'get_group_progress', ['group_id' => $group->id]))->toHaveCount(2)
        ->and($queriesOf($educator, 'find_learners_behind', ['group_id' => $group->id]))->toHaveCount(2)
        ->and($queriesOf($educator, 'find_hardest_assignments', ['group_id' => $group->id, 'limit' => 20]))->toHaveCount(2)
        ->and($queriesOf($educator, 'get_learner_summary', ['learner_id' => $learner->id]))->toHaveCount(2)
        ->and($byName)->toHaveCount(3)
        ->and($byName[0])->toContain('`users`.`name` like ?')
        ->and($byName[1])->not->toContain(' like ');
})->with([
    '60 learners and 100 assignments' => [60, 100],
    '120 learners and 200 assignments' => [120, 200],
]);

test('an ambiguous name is one query, the candidates query', function () use ($queriesOf) {
    $group = Group::factory()->create();
    $educator = educatorOf($group);
    seedLargeGroup($group, $educator, 60, 100);

    expect($queriesOf($educator, 'get_learner_summary', ['name' => 'Learner']))->toHaveCount(1);
});
