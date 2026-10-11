<?php

use App\Enums\ContentType;
use App\Enums\DerivedStatus;
use App\Queries\LearnerAggregation;
use App\Queries\Results\AssignmentStatus;
use App\Queries\Results\StatusCounts;
use App\Support\InstitutionTime;
use Carbon\CarbonImmutable;

test('a learner with no pair rows has zero counts, no average and is not behind', function () {
    $progress = LearnerAggregation::fromRows(101, 'Sara Lindqvist', [], new InstitutionTime('UTC'), CarbonImmutable::parse('2026-10-09 08:00:00'));

    expect($progress->learnerId)->toBe(101)
        ->and($progress->learnerName)->toBe('Sara Lindqvist')
        ->and($progress->counts)->toEqual(new StatusCounts(notStarted: 0, inProgress: 0, completed: 0, overdue: 0))
        ->and($progress->averageScore)->toBeNull()
        ->and($progress->behind)->toBeFalse()
        ->and($progress->reasons)->toBe([])
        ->and($progress->statuses)->toBe([]);
});

test('the average counts completed question sets only', function () {
    $rows = [
        pairRow(['assignment_id' => 1, 'type' => 'question_set', 'progress_status' => 'completed', 'status' => 'completed', 'score' => 40]),
        pairRow(['assignment_id' => 2, 'type' => 'article', 'progress_status' => 'completed', 'status' => 'completed', 'score' => 0]),
        pairRow(['assignment_id' => 3, 'type' => 'question_set', 'progress_status' => 'in_progress', 'status' => 'in_progress', 'score' => 0]),
        pairRow(['assignment_id' => 4, 'type' => 'question_set', 'progress_status' => 'completed', 'status' => 'completed', 'score' => 60]),
    ];

    $progress = LearnerAggregation::fromRows(101, 'Sara Lindqvist', $rows, new InstitutionTime('UTC'), CarbonImmutable::parse('2026-10-09 08:00:00'));

    expect($progress->averageScore)->toBe(50.0)
        ->and($progress->behind)->toBeFalse()
        ->and($progress->reasons)->toBe([])
        ->and($progress->counts)->toEqual(new StatusCounts(notStarted: 0, inProgress: 1, completed: 3, overdue: 0));
});

test('one overdue pair makes the learner behind with its reason', function () {
    $rows = [
        pairRow(['assignment_id' => 40, 'title' => 'ECG Basics', 'due_at' => '2026-10-02 21:59:59', 'status' => 'overdue']),
        pairRow(['assignment_id' => 41, 'due_at' => '2026-10-16 21:59:59', 'status' => 'not_started']),
    ];

    $progress = LearnerAggregation::fromRows(101, 'Sara Lindqvist', $rows, new InstitutionTime('Europe/Berlin'), CarbonImmutable::parse('2026-10-09 08:00:00'));

    expect($progress->behind)->toBeTrue()
        ->and($progress->averageScore)->toBeNull()
        ->and($progress->counts)->toEqual(new StatusCounts(notStarted: 1, inProgress: 0, completed: 0, overdue: 1))
        ->and(array_map(fn ($reason) => $reason->text(), $progress->reasons))->toBe(['Overdue: ECG Basics, due 2026-10-02, 7 days overdue']);
});

test('statuses follow the row order with dates in the institution time zone', function () {
    $rows = [
        pairRow(['assignment_id' => 40, 'title' => 'ECG Basics', 'type' => 'article', 'due_at' => '2026-10-02 21:59:59', 'progress_status' => 'completed', 'status' => 'completed', 'started_at' => '2026-10-01 08:00:00', 'completed_at' => '2026-10-01 10:00:00']),
        pairRow(['assignment_id' => 41, 'title' => 'Heart Failure Question Set', 'due_at' => '2026-10-16 21:59:59', 'status' => 'not_started']),
    ];

    $statuses = LearnerAggregation::fromRows(101, 'Sara Lindqvist', $rows, new InstitutionTime('Europe/Berlin'), CarbonImmutable::parse('2026-10-09 08:00:00'))->statuses;

    expect(array_map(fn (AssignmentStatus $status) => [
        $status->assignmentId,
        $status->title,
        $status->type,
        $status->dueAt->toIso8601String(),
        $status->status,
        $status->score,
        $status->completedAt?->toIso8601String(),
    ], $statuses))->toBe([
        [40, 'ECG Basics', ContentType::Article, '2026-10-02T23:59:59+02:00', DerivedStatus::Completed, null, '2026-10-01T12:00:00+02:00'],
        [41, 'Heart Failure Question Set', ContentType::QuestionSet, '2026-10-16T23:59:59+02:00', DerivedStatus::NotStarted, null, null],
    ]);
});
