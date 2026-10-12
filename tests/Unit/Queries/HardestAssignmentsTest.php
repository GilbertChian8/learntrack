<?php

use App\Enums\AssignmentScope;
use App\Enums\ContentType;
use App\Exceptions\NotFoundException;
use App\Models\Assignment;
use App\Models\ContentItem;
use App\Models\Group;
use App\Models\Progress;
use App\Models\User;
use App\Queries\HardestAssignments;
use App\Queries\Results\RankedAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-09 12:20:00');
});

$seedGroup = function (): array {
    $group = Group::factory()->create(['name' => 'Cardiology Group B']);
    $educator = educatorOf($group);
    $ada = learnerIn($group);
    $ben = learnerIn($group);
    $assign = fn (string $title, ContentType $type, string $dueAt, AssignmentScope $scope = AssignmentScope::Group) => Assignment::factory()
        ->for($group)->for(ContentItem::factory()->state(['title' => $title, 'type' => $type]), 'contentItem')
        ->create(['due_at' => $dueAt, 'scope' => $scope]);
    $complete = fn (Assignment $assignment, User $learner, ?int $score) => Progress::factory()
        ->for($assignment)->for($learner, 'user')->completed($score)->create();

    $assignments = [
        'later' => $assign('Later', ContentType::Article, '2026-10-20 21:59:59'),
        'earlier' => $assign('Earlier', ContentType::Article, '2026-10-18 21:59:59'),
        'sameDueFirst' => $assign('Same due, first', ContentType::Article, '2026-10-25 21:59:59'),
        'sameDueSecond' => $assign('Same due, second', ContentType::Article, '2026-10-25 21:59:59'),
        'lowAverage' => $assign('Low average', ContentType::QuestionSet, '2026-10-15 21:59:59'),
        'highAverage' => $assign('High average', ContentType::QuestionSet, '2026-10-12 21:59:59', AssignmentScope::Learners),
        'nobody' => $assign('Nobody', ContentType::QuestionSet, '2026-10-01 21:59:59', AssignmentScope::Learners),
    ];
    $assignments['highAverage']->learners()->attach($ada);

    $complete($assignments['lowAverage'], $ada, 48);
    $complete($assignments['lowAverage'], $ben, 49);
    $complete($assignments['highAverage'], $ada, 100);

    return [$group, $educator, $assignments];
};

test('ties on the rate go to the lower average, then the earlier due date, then the lower id', function () use ($seedGroup) {
    [$group, $educator, $assignments] = $seedGroup();

    $result = HardestAssignments::for($educator, $group->id, 20);

    expect($result->groupId)->toBe($group->id)
        ->and($result->groupName)->toBe('Cardiology Group B')
        ->and(array_map(fn (RankedAssignment $ranked) => [$ranked->stats->id, $ranked->reason], $result->assignments))->toBe([
            [$assignments['earlier']->id, '0 of 2 learners completed it (0%)'],
            [$assignments['later']->id, '0 of 2 learners completed it (0%)'],
            [$assignments['sameDueFirst']->id, '0 of 2 learners completed it (0%)'],
            [$assignments['sameDueSecond']->id, '0 of 2 learners completed it (0%)'],
            [$assignments['lowAverage']->id, '2 of 2 learners completed it (100%), average score 48.5'],
            [$assignments['highAverage']->id, '1 of 1 learner completed it (100%), average score 100'],
        ]);
});

test('limit takes the first ones', function () use ($seedGroup) {
    [$group, $educator, $assignments] = $seedGroup();

    $result = HardestAssignments::for($educator, $group->id, 3);

    expect(array_map(fn (RankedAssignment $ranked) => $ranked->stats->id, $result->assignments))
        ->toBe([$assignments['earlier']->id, $assignments['later']->id, $assignments['sameDueFirst']->id]);
});

test('it reads the group through GroupProgress: two queries, and not found outside the educator\'s groups', function () use ($seedGroup) {
    [$group, $educator] = $seedGroup();

    DB::enableQueryLog();
    HardestAssignments::for($educator, $group->id, 5);
    DB::disableQueryLog();

    expect(DB::getQueryLog())->toHaveCount(2)
        ->and(fn () => HardestAssignments::for(User::factory()->educator()->create(), $group->id, 5))
        ->toThrow(NotFoundException::class, 'Group not found.');
});
