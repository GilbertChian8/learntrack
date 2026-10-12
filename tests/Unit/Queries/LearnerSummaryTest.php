<?php

use App\Exceptions\NotFoundException;
use App\Models\Assignment;
use App\Models\Group;
use App\Models\User;
use App\Queries\GroupProgress;
use App\Queries\LearnerSummary;
use App\Queries\Results\AssignmentStatus;
use App\Queries\Results\LearnerCandidate;
use App\Queries\Results\LearnerGroupProgress;
use App\Queries\Results\LearnerProgress;
use App\Queries\Results\Reason;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-09 12:20:00');
});

test('each group the educator teaches equals that learner\'s entry in GroupProgress, ordered by name, not id', function () {
    ['group' => $groupA, 'educator' => $educator, 'sara' => $sara] = cardiologyProgress();
    $groupB = Group::factory()->create(['institution_id' => $groupA->institution_id, 'name' => 'Anatomy Group A']);
    $groupB->educators()->attach($educator);
    $groupB->learners()->attach($sara);
    Assignment::factory()->for($groupB)->create(['due_at' => '2026-10-01 21:59:59']);
    $foreign = Group::factory()->create(['institution_id' => $groupA->institution_id, 'name' => 'Anatomy Group 0']);
    educatorOf($foreign);
    $foreign->learners()->attach($sara);

    $summary = LearnerSummary::for($educator, $sara->id);
    $view = fn (LearnerProgress $progress) => [
        $progress->learnerId,
        $progress->behind,
        array_map(fn (Reason $reason) => $reason->toArray(), $progress->reasons),
        $progress->averageScore,
        (array) $progress->counts,
        array_map(fn (AssignmentStatus $status) => [
            $status->assignmentId, $status->title, $status->type->value, $status->dueAt->toIso8601String(),
            $status->status->value, $status->score, $status->completedAt?->toIso8601String(),
        ], $progress->statuses),
    ];
    $inGroupProgress = fn (Group $group) => $view(collect(GroupProgress::for($educator, $group->id)->learners)
        ->firstOrFail(fn (LearnerProgress $learner) => $learner->learnerId === $sara->id));

    expect($summary->learnerId)->toBe($sara->id)
        ->and($summary->learnerName)->toBe('Sara Lindqvist')
        ->and(array_map(fn (LearnerGroupProgress $group) => [$group->groupId, $group->groupName], $summary->groups))
        ->toBe([[$groupB->id, 'Anatomy Group A'], [$groupA->id, 'Cardiology Group A']])
        ->and($view($summary->groups[0]->progress))->toBe($inGroupProgress($groupB))
        ->and($view($summary->groups[1]->progress))->toBe($inGroupProgress($groupA))
        ->and($summary->groups[0]->progress->behind)->toBeTrue();
});

test('the summary is two queries', function () {
    ['educator' => $educator, 'sara' => $sara] = cardiologyProgress();

    DB::enableQueryLog();
    LearnerSummary::for($educator, $sara->id);
    DB::disableQueryLog();

    expect(DB::getQueryLog())->toHaveCount(2);
});

test('a learner outside the educator\'s groups and a missing id are not found, after one query', function (Closure $learnerIdFor) {
    ['group' => $group, 'educator' => $educator] = cardiologyProgress();
    $foreign = Group::factory()->create(['institution_id' => $group->institution_id]);
    educatorOf($foreign);
    $learnerId = $learnerIdFor(learnerIn($foreign));

    DB::enableQueryLog();
    expect(fn () => LearnerSummary::for($educator, $learnerId))->toThrow(NotFoundException::class, 'Learner not found.');
    DB::disableQueryLog();

    expect(DB::getQueryLog())->toHaveCount(1);
})->with([
    'another educator\'s learner' => fn (User $learner) => $learner->id,
    'a missing id' => fn (User $learner) => $learner->id + 1000,
]);

test('candidates match the name anywhere in any case, only in the educator\'s groups, ordered by name then id, in one query', function () {
    ['group' => $groupA, 'educator' => $educator, 'sara' => $sara, 'tom' => $tom] = cardiologyProgress();
    $groupB = Group::factory()->create(['institution_id' => $groupA->institution_id, 'name' => 'Anatomy Group A']);
    $groupB->educators()->attach($educator);
    $groupB->learners()->attach([$sara->id, $tom->id]);
    $secondSara = learnerIn($groupB);
    $secondSara->update(['name' => 'Sara Lindqvist']);
    $abbott = learnerIn($groupB);
    $abbott->update(['name' => 'Sara Abbott']);
    $foreign = Group::factory()->create(['institution_id' => $groupA->institution_id]);
    educatorOf($foreign);
    learnerIn($foreign)->update(['name' => 'Sara Outsider']);

    DB::enableQueryLog();
    $candidates = LearnerSummary::candidates($educator, 'sAR');
    DB::disableQueryLog();

    expect(array_map(fn (LearnerCandidate $candidate) => [$candidate->id, $candidate->name, $candidate->groupNames], $candidates))->toBe([
        [$abbott->id, 'Sara Abbott', ['Anatomy Group A']],
        [$sara->id, 'Sara Lindqvist', ['Anatomy Group A', 'Cardiology Group A']],
        [$secondSara->id, 'Sara Lindqvist', ['Anatomy Group A']],
    ])
        ->and(DB::getQueryLog())->toHaveCount(1)
        ->and(LearnerSummary::candidates($educator, 'Becker'))->toHaveCount(1);
});

test('% and _ in the name match themselves, not any text', function () {
    ['educator' => $educator] = cardiologyProgress();

    expect(LearnerSummary::candidates($educator, '%'))->toBe([])
        ->and(LearnerSummary::candidates($educator, '_a'))->toBe([]);
});
