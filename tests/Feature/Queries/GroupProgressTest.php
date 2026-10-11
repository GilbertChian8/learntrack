<?php

use App\Enums\ContentType;
use App\Exceptions\NotFoundException;
use App\Models\Assignment;
use App\Models\ContentItem;
use App\Models\Group;
use App\Models\Institution;
use App\Models\Progress;
use App\Models\User;
use App\Queries\GroupProgress;
use App\Queries\Results\AssignmentStats;
use App\Queries\Results\AssignmentStatus;
use App\Queries\Results\LearnerProgress;
use App\Queries\Results\Reason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-09 08:00:00');
});

$seedCardiologyGroup = function (): array {
    $institution = Institution::factory()->create(['name' => 'Northside Medical School', 'timezone' => 'Europe/Berlin']);
    $group = Group::factory()->for($institution)->create(['name' => 'Cardiology Group A']);
    $educator = educatorOf($group);

    $member = function (string $name) use ($institution, $group): User {
        $learner = User::factory()->learner()->for($institution)->create(['name' => $name]);
        $group->learners()->attach($learner);

        return $learner;
    };
    $assign = function (string $title, ContentType $type, string $dueAt, array $learners = []) use ($group, $educator): Assignment {
        $factory = Assignment::factory()->for($group)
            ->for(ContentItem::factory()->state(['title' => $title, 'topic' => 'Cardiology', 'type' => $type]), 'contentItem');

        return ($learners === [] ? $factory : $factory->forLearners($learners))
            ->create(['due_at' => $dueAt, 'created_by' => $educator->id]);
    };
    $complete = fn (Assignment $assignment, User $learner, ?int $score, string $at) => Progress::factory()
        ->for($assignment)->for($learner, 'user')->completed($score)
        ->create(['started_at' => $at, 'completed_at' => $at]);
    $start = fn (Assignment $assignment, User $learner, string $at) => Progress::factory()
        ->for($assignment)->for($learner, 'user')
        ->create(['started_at' => $at]);

    $cleo = $member('Cleo Diaz');
    $ada = $member('Ada Brandt');
    $ben = $member('Ben Carter');
    $zoe = $member('Zoe Young');

    $arrhythmia = $assign('Arrhythmia Question Set', ContentType::QuestionSet, '2026-10-05 21:59:59');
    $ecg = $assign('ECG Basics', ContentType::Article, '2026-10-02 21:59:59');
    $heartFailure = $assign('Heart Failure Question Set', ContentType::QuestionSet, '2026-10-16 21:59:59', [$ada, $ben, $cleo]);
    $lipids = $assign('Lipid Disorders Question Set', ContentType::QuestionSet, '2026-10-20 21:59:59', [$zoe]);
    $cardiomyopathy = $assign('Cardiomyopathy Question Set', ContentType::QuestionSet, '2026-10-23 21:59:59');
    $valves = $assign('Valve Disease Question Set', ContentType::QuestionSet, '2026-10-01 21:59:59');

    $complete($ecg, $ada, null, '2026-10-01 10:00:00');
    $complete($arrhythmia, $ada, 40, '2026-10-04 10:00:00');
    $complete($heartFailure, $ada, 45, '2026-10-08 10:00:00');
    $complete($valves, $ada, 10, '2026-09-30 10:00:00');
    $start($arrhythmia, $ben, '2026-10-03 09:00:00');
    $start($heartFailure, $ben, '2026-10-08 09:00:00');
    $complete($ecg, $cleo, null, '2026-10-03 10:00:00');
    $complete($arrhythmia, $cleo, 81, '2026-10-05 10:00:00');
    $complete($heartFailure, $cleo, 70, '2026-10-07 10:00:00');
    $complete($ecg, $zoe, null, '2026-10-01 10:00:00');

    $valves->update(['removed_at' => now(), 'removed_key' => $valves->id]);
    $group->learners()->detach($zoe);
    $dev = $member('Dev Evans');

    return [
        'educator' => $educator,
        'group' => $group,
        'learners' => compact('ada', 'ben', 'cleo', 'dev'),
        'assignments' => compact('ecg', 'arrhythmia', 'heartFailure', 'lipids', 'cardiomyopathy'),
    ];
};

test('the result has the group, every current member and every active assignment', function () use ($seedCardiologyGroup) {
    ['educator' => $educator, 'group' => $group, 'learners' => $learners, 'assignments' => $assignments] = $seedCardiologyGroup();

    $result = GroupProgress::for($educator, $group->id);

    expect($result->groupId)->toBe($group->id)
        ->and($result->groupName)->toBe('Cardiology Group A')
        ->and($result->institutionName)->toBe('Northside Medical School')
        ->and($result->generatedAt->toIso8601String())->toBe('2026-10-09T10:00:00+02:00')
        ->and($result->learnerCount)->toBe(4)
        ->and($result->behindCount)->toBe(3)
        ->and(array_map(fn (LearnerProgress $learner) => $learner->learnerId, $result->learners))
        ->toBe([$learners['ada']->id, $learners['ben']->id, $learners['cleo']->id, $learners['dev']->id])
        ->and(array_map(fn (AssignmentStats $assignment) => $assignment->id, $result->assignments))
        ->toBe([
            $assignments['ecg']->id,
            $assignments['arrhythmia']->id,
            $assignments['heartFailure']->id,
            $assignments['lipids']->id,
            $assignments['cardiomyopathy']->id,
        ]);
});

test('per assignment numbers match hand-computed values', function () use ($seedCardiologyGroup) {
    ['educator' => $educator, 'group' => $group] = $seedCardiologyGroup();

    $assignments = GroupProgress::for($educator, $group->id)->assignments;

    expect(array_map(fn (AssignmentStats $assignment) => [
        $assignment->title,
        $assignment->type->value,
        $assignment->scope->value,
        $assignment->dueAt->toIso8601String(),
        $assignment->targetedCount,
        $assignment->completedCount,
        $assignment->completionRate,
        $assignment->averageScore,
    ], $assignments))->toBe([
        ['ECG Basics', 'article', 'group', '2026-10-02T23:59:59+02:00', 4, 2, 0.5, null],
        ['Arrhythmia Question Set', 'question_set', 'group', '2026-10-05T23:59:59+02:00', 4, 2, 0.5, 60.5],
        ['Heart Failure Question Set', 'question_set', 'learners', '2026-10-16T23:59:59+02:00', 3, 2, 0.67, 57.5],
        ['Lipid Disorders Question Set', 'question_set', 'learners', '2026-10-20T23:59:59+02:00', 0, 0, 0.0, null],
        ['Cardiomyopathy Question Set', 'question_set', 'group', '2026-10-23T23:59:59+02:00', 4, 0, 0.0, null],
    ]);
});

test('per learner statuses, counts, averages and reasons match hand-computed values', function () use ($seedCardiologyGroup) {
    ['educator' => $educator, 'group' => $group, 'assignments' => $assignments] = $seedCardiologyGroup();
    $ids = array_map(fn (Assignment $assignment) => $assignment->id, $assignments);

    $learners = GroupProgress::for($educator, $group->id)->learners;

    expect(array_map(fn (LearnerProgress $learner) => [
        'name' => $learner->learnerName,
        'statuses' => array_map(fn (AssignmentStatus $status) => [$status->assignmentId, $status->status->value, $status->score], $learner->statuses),
        'counts' => [$learner->counts->notStarted, $learner->counts->inProgress, $learner->counts->completed, $learner->counts->overdue],
        'average' => $learner->averageScore,
        'behind' => $learner->behind,
        'reasons' => array_map(fn (Reason $reason) => $reason->text(), $learner->reasons),
    ], $learners))->toBe([
        [
            'name' => 'Ada Brandt',
            'statuses' => [
                [$ids['ecg'], 'completed', null],
                [$ids['arrhythmia'], 'completed', 40],
                [$ids['heartFailure'], 'completed', 45],
                [$ids['cardiomyopathy'], 'not_started', null],
            ],
            'counts' => [1, 0, 3, 0],
            'average' => 42.5,
            'behind' => true,
            'reasons' => ['Average question set score 42.5 is below 50 (scores: 40, 45)'],
        ],
        [
            'name' => 'Ben Carter',
            'statuses' => [
                [$ids['ecg'], 'overdue', null],
                [$ids['arrhythmia'], 'overdue', null],
                [$ids['heartFailure'], 'in_progress', null],
                [$ids['cardiomyopathy'], 'not_started', null],
            ],
            'counts' => [1, 1, 0, 2],
            'average' => null,
            'behind' => true,
            'reasons' => [
                'Overdue: ECG Basics, due 2026-10-02, 7 days overdue',
                'Overdue: Arrhythmia Question Set, due 2026-10-05, 4 days overdue',
            ],
        ],
        [
            'name' => 'Cleo Diaz',
            'statuses' => [
                [$ids['ecg'], 'completed', null],
                [$ids['arrhythmia'], 'completed', 81],
                [$ids['heartFailure'], 'completed', 70],
                [$ids['cardiomyopathy'], 'not_started', null],
            ],
            'counts' => [1, 0, 3, 0],
            'average' => 75.5,
            'behind' => false,
            'reasons' => [],
        ],
        [
            'name' => 'Dev Evans',
            'statuses' => [
                [$ids['ecg'], 'overdue', null],
                [$ids['arrhythmia'], 'overdue', null],
                [$ids['cardiomyopathy'], 'not_started', null],
            ],
            'counts' => [1, 0, 0, 2],
            'average' => null,
            'behind' => true,
            'reasons' => [
                'Overdue: ECG Basics, due 2026-10-02, 7 days overdue',
                'Overdue: Arrhythmia Question Set, due 2026-10-05, 4 days overdue',
            ],
        ],
    ]);
});

test('a learner added after a group assignment gets it, and a learner outside a subset does not', function () {
    $group = Group::factory()->create();
    $educator = educatorOf($group);
    $first = learnerIn($group);
    $groupWide = Assignment::factory()->for($group)->create(['due_at' => '2026-10-16 21:59:59']);
    $subset = Assignment::factory()->for($group)->forLearners([$first])->create(['due_at' => '2026-10-20 21:59:59']);
    $later = learnerIn($group);

    $result = GroupProgress::for($educator, $group->id);
    $statusesOf = fn (User $learner) => array_map(
        fn (AssignmentStatus $status) => [$status->assignmentId, $status->status->value],
        collect($result->learners)->firstOrFail(fn (LearnerProgress $progress) => $progress->learnerId === $learner->id)->statuses,
    );

    expect($statusesOf($later))->toBe([[$groupWide->id, 'not_started']])
        ->and($statusesOf($first))->toBe([[$groupWide->id, 'not_started'], [$subset->id, 'not_started']]);
});

test('a member that no assignment targets appears with zero counts and is not behind', function () {
    $group = Group::factory()->create();
    $educator = educatorOf($group);
    $targeted = learnerIn($group);
    $untargeted = learnerIn($group);
    Assignment::factory()->for($group)->forLearners([$targeted])->create(['due_at' => '2026-10-01 21:59:59']);

    $learner = collect(GroupProgress::for($educator, $group->id)->learners)
        ->firstOrFail(fn (LearnerProgress $progress) => $progress->learnerId === $untargeted->id);

    expect($learner->learnerName)->toBe($untargeted->name)
        ->and([$learner->counts->notStarted, $learner->counts->inProgress, $learner->counts->completed, $learner->counts->overdue])->toBe([0, 0, 0, 0])
        ->and($learner->averageScore)->toBeNull()
        ->and($learner->behind)->toBeFalse()
        ->and($learner->reasons)->toBe([])
        ->and($learner->statuses)->toBe([]);
});

test('another educator\'s group and a missing id are not found, before any pair query runs', function (Closure $groupIdFor) {
    $group = Group::factory()->create();
    educatorOf($group);
    Assignment::factory()->for($group)->create();
    $otherEducator = User::factory()->educator()->create(['institution_id' => $group->institution_id]);
    $groupId = $groupIdFor($group);

    DB::enableQueryLog();

    expect(fn () => GroupProgress::for($otherEducator, $groupId))->toThrow(NotFoundException::class, 'Group not found.')
        ->and(DB::getQueryLog())->toHaveCount(1);
})->with([
    'another educator\'s group' => fn (Group $group) => $group->id,
    'a missing id' => fn (Group $group) => $group->id + 1000,
]);

test('a group\'s progress is exactly two queries whatever its size', function (int $learnerCount, int $assignmentCount) {
    $group = Group::factory()->create();
    $educator = educatorOf($group);
    $learners = User::factory()->learner()->count($learnerCount)->create(['institution_id' => $group->institution_id]);
    $group->learners()->attach($learners);
    $assignments = Assignment::factory()->count($assignmentCount)->for($group)
        ->sequence(fn (Sequence $sequence) => ['due_at' => now()->addDays($sequence->index % 30 - 15)->startOfSecond()])
        ->create(['created_by' => $educator->id]);
    Assignment::factory()->for($group)->forLearners($learners->take(5)->all())->create(['created_by' => $educator->id]);

    $progress = [];
    foreach ($assignments as $a => $assignment) {
        foreach ($learners as $l => $learner) {
            if (($a + $l) % 2 === 0) {
                $progress[] = [
                    'assignment_id' => $assignment->id,
                    'user_id' => $learner->id,
                    'status' => $l % 4 === 0 ? 'in_progress' : 'completed',
                    'score' => $l % 4 === 0 ? null : ($a * 7 + $l * 3) % 101,
                    'started_at' => now()->subDays(3)->toDateTimeString(),
                    'completed_at' => $l % 4 === 0 ? null : now()->subMinutes($a * $learnerCount + $l)->toDateTimeString(),
                ];
            }
        }
    }
    foreach (array_chunk($progress, 1000) as $chunk) {
        DB::table('progress')->insert($chunk);
    }

    DB::enableQueryLog();
    $result = GroupProgress::for($educator, $group->id);
    $queries = DB::getQueryLog();

    expect($queries)->toHaveCount(2)
        ->and($result->learnerCount)->toBe($learnerCount)
        ->and($result->assignments)->toHaveCount($assignmentCount + 1);

    fwrite(STDERR, sprintf("\n  pair query, %d learners x %d assignments: %.1f ms\n", $learnerCount, $assignmentCount, $queries[1]['time']));
})->with([
    '60 learners and 100 assignments' => [60, 100],
    '120 learners and 200 assignments' => [120, 200],
]);
