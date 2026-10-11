<?php

use App\Queries\BehindRule;
use App\Queries\Results\Reason;
use App\Support\InstitutionTime;
use Carbon\CarbonImmutable;

test('one overdue pair makes a learner behind', function () {
    expect(BehindRule::isBehind(1, null))->toBeTrue()
        ->and(BehindRule::isBehind(1, 90.0))->toBeTrue()
        ->and(BehindRule::isBehind(0, 90.0))->toBeFalse();
});

test('an average below the threshold makes a learner behind', function (?float $average, bool $behind) {
    expect(BehindRule::isBehind(0, $average))->toBe($behind);
})->with([
    'average 49.9' => [49.9, true],
    'exactly 50' => [50.0, false],
    'no completed question set' => [null, false],
]);

test('the average is rounded to 1 decimal and null without a completed question set', function () {
    $scored = fn (int ...$scores) => array_map(fn (int $score) => pairRow(['status' => 'completed', 'score' => $score]), $scores);

    expect(BehindRule::averageScore($scored(40, 45)))->toBe(42.5)
        ->and(BehindRule::averageScore($scored(33, 34, 34)))->toBe(33.7)
        ->and(BehindRule::averageScore([]))->toBeNull()
        ->and(BehindRule::reasons([], [], new InstitutionTime('UTC'), CarbonImmutable::parse('2026-10-09 08:00:00')))->toBe([]);
});

test('an overdue reason counts calendar days in the institution time zone', function () {
    [$reason] = BehindRule::reasons(
        [pairRow(['assignment_id' => 40, 'title' => 'ECG Basics', 'due_at' => '2026-10-02 21:59:59', 'status' => 'overdue'])],
        [],
        new InstitutionTime('Europe/Berlin'),
        CarbonImmutable::parse('2026-10-09T08:00:00Z'),
    );

    expect($reason->toArray())->toBe([
        'kind' => 'overdue',
        'assignment_id' => 40,
        'title' => 'ECG Basics',
        'due_at' => '2026-10-02T23:59:59+02:00',
        'days_overdue' => 7,
        'text' => 'Overdue: ECG Basics, due 2026-10-02, 7 days overdue',
    ]);
});

test('the overdue text matches the contract', function (string $dueAt, string $now, string $text) {
    [$reason] = BehindRule::reasons(
        [pairRow(['title' => 'ECG Basics', 'due_at' => $dueAt, 'status' => 'overdue'])],
        [],
        new InstitutionTime('Europe/Berlin'),
        CarbonImmutable::parse($now),
    );

    expect($reason->text())->toBe($text);
})->with([
    'several days' => ['2026-10-02 21:59:59', '2026-10-09 08:00:00', 'Overdue: ECG Basics, due 2026-10-02, 7 days overdue'],
    'one day' => ['2026-10-08 21:59:59', '2026-10-09 08:00:00', 'Overdue: ECG Basics, due 2026-10-08, 1 day overdue'],
    'earlier today' => ['2026-10-09 07:00:00', '2026-10-09 08:00:00', 'Overdue: ECG Basics, due 2026-10-09, earlier today'],
    'already the next day in Berlin' => ['2026-10-08 22:30:00', '2026-10-09 08:00:00', 'Overdue: ECG Basics, due 2026-10-09, earlier today'],
    'across the start of summer time' => ['2027-03-26 22:59:59', '2027-03-29 08:00:00', 'Overdue: ECG Basics, due 2027-03-26, 3 days overdue'],
]);

test('a low-average reason carries the scores and the contract text', function () {
    $reasons = BehindRule::reasons(
        [],
        [
            pairRow(['assignment_id' => 41, 'title' => 'Heart Failure Question Set', 'status' => 'completed', 'score' => 40]),
            pairRow(['assignment_id' => 43, 'title' => 'Arrhythmia Question Set', 'status' => 'completed', 'score' => 45]),
        ],
        new InstitutionTime('Europe/Berlin'),
        CarbonImmutable::parse('2026-10-09 08:00:00'),
    );

    expect($reasons)->toHaveCount(1)
        ->and($reasons[0]->toArray())->toBe([
            'kind' => 'low_average',
            'average_score' => 42.5,
            'threshold' => 50,
            'scores' => [
                ['assignment_id' => 41, 'title' => 'Heart Failure Question Set', 'score' => 40],
                ['assignment_id' => 43, 'title' => 'Arrhythmia Question Set', 'score' => 45],
            ],
            'text' => 'Average question set score 42.5 is below 50 (scores: 40, 45)',
        ]);
});

test('a whole-number average is written without a decimal', function (array $scores, string $text) {
    $completed = array_map(fn (int $score) => pairRow(['status' => 'completed', 'score' => $score]), $scores);

    [$reason] = BehindRule::reasons([], $completed, new InstitutionTime('UTC'), CarbonImmutable::parse('2026-10-09 08:00:00'));

    expect($reason->text())->toBe($text);
})->with([
    'one score' => [[40], 'Average question set score 40 is below 50 (scores: 40)'],
    'an average that rounds to a whole number' => [[39, 41], 'Average question set score 40 is below 50 (scores: 39, 41)'],
]);

test('overdue reasons come first in due date order, then one low-average reason', function () {
    $reasons = BehindRule::reasons(
        [
            pairRow(['assignment_id' => 40, 'due_at' => '2026-10-02 21:59:59', 'status' => 'overdue']),
            pairRow(['assignment_id' => 43, 'due_at' => '2026-10-05 21:59:59', 'status' => 'overdue']),
        ],
        [
            pairRow(['assignment_id' => 41, 'status' => 'completed', 'score' => 30]),
            pairRow(['assignment_id' => 44, 'status' => 'completed', 'score' => 20]),
        ],
        new InstitutionTime('UTC'),
        CarbonImmutable::parse('2026-10-09 08:00:00'),
    );

    expect(array_map(fn (Reason $reason) => [$reason->toArray()['kind'], $reason->toArray()['assignment_id'] ?? null], $reasons))
        ->toBe([['overdue', 40], ['overdue', 43], ['low_average', null]]);
});

test('the rule in words is the contract sentence', function () {
    expect(BehindRule::ruleText())->toBe('A learner is behind when they have at least one overdue assignment in the group, or their average score over completed question sets in the group is below 50.')
        ->and(BehindRule::SCORE_THRESHOLD)->toBe(50);
});
