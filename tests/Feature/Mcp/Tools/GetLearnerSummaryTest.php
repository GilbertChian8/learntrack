<?php

use App\Models\Assignment;
use App\Models\Group;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-09 12:20:00');
});

/**
 * Cardiology Group B, taught by another educator, with Sara and one assignment she has not started.
 */
$groupB = function (Group $groupA, User $sara): Group {
    $groupB = Group::factory()->create(['institution_id' => $groupA->institution_id, 'name' => 'Cardiology Group B']);
    educatorOf($groupB);
    $groupB->learners()->attach($sara);
    Assignment::factory()->for($groupB)->create(['due_at' => '2026-10-20 21:59:59']);

    return $groupB;
};

$saraInGroupA = fn (array $fixture): array => [
    'id' => $fixture['group']->id,
    'name' => 'Cardiology Group A',
    'behind' => true,
    'reasons' => [[
        'kind' => 'low_average',
        'average_score' => 42.5,
        'threshold' => 50,
        'scores' => [
            ['assignment_id' => $fixture['assignments']['arrhythmia']->id, 'title' => 'Arrhythmia Question Set', 'score' => 45],
            ['assignment_id' => $fixture['assignments']['heartFailure']->id, 'title' => 'Heart Failure Question Set', 'score' => 40],
        ],
        'text' => 'Average question set score 42.5 is below 50 (scores: 45, 40)',
    ]],
    'average_score' => 42.5,
    'counts' => ['not_started' => 1, 'in_progress' => 0, 'completed' => 3, 'overdue' => 0],
    'assignments' => [
        ['assignment_id' => $fixture['assignments']['ecg']->id, 'title' => 'ECG Basics', 'type' => 'article', 'due_at' => '2026-10-02T23:59:59+02:00', 'status' => 'completed', 'score' => null, 'completed_at' => '2026-10-01T18:02:00+02:00'],
        ['assignment_id' => $fixture['assignments']['arrhythmia']->id, 'title' => 'Arrhythmia Question Set', 'type' => 'question_set', 'due_at' => '2026-10-05T23:59:59+02:00', 'status' => 'completed', 'score' => 45, 'completed_at' => '2026-10-05T11:00:00+02:00'],
        ['assignment_id' => $fixture['assignments']['heartFailure']->id, 'title' => 'Heart Failure Question Set', 'type' => 'question_set', 'due_at' => '2026-10-16T23:59:59+02:00', 'status' => 'completed', 'score' => 40, 'completed_at' => '2026-10-08T20:15:00+02:00'],
        ['assignment_id' => $fixture['assignments']['cardiomyopathy']->id, 'title' => 'Cardiomyopathy Question Set', 'type' => 'question_set', 'due_at' => '2026-10-23T23:59:59+02:00', 'status' => 'not_started', 'score' => null, 'completed_at' => null],
    ],
];

test('by id: only the groups the caller teaches, with the contract text', function () use ($groupB, $saraInGroupA) {
    $fixture = cardiologyProgress();
    $groupB($fixture['group'], $fixture['sara']);

    actingAsEducator($fixture['educator']);
    $result = callTool('get_learner_summary', ['learner_id' => $fixture['sara']->id])->json('result');

    expect($result['isError'])->toBeFalse()
        ->and($result['structuredContent'])->toBe([
            'learner' => ['id' => $fixture['sara']->id, 'name' => 'Sara Lindqvist'],
            'groups' => [$saraInGroupA($fixture)],
            'candidates' => [],
        ])
        ->and($result['content'])->toBe([[
            'type' => 'text',
            'text' => 'Sara Lindqvist is in 1 of your groups. '
                .'Cardiology Group A: behind (Average question set score 42.5 is below 50 (scores: 45, 40)); 3 completed, 0 in progress, 1 not started, 0 overdue.',
        ]]);
});

test('by id: a shared group shows once the caller teaches it too, ordered by name', function () use ($groupB) {
    $fixture = cardiologyProgress();
    $groupB = $groupB($fixture['group'], $fixture['sara']);
    $groupB->educators()->attach($fixture['educator']);

    actingAsEducator($fixture['educator']);
    $result = callTool('get_learner_summary', ['learner_id' => $fixture['sara']->id])->json('result');
    $groups = $result['structuredContent']['groups'];

    expect(array_column($groups, 'id'))->toBe([$fixture['group']->id, $groupB->id])
        ->and($groups[1])->toMatchArray([
            'behind' => false,
            'reasons' => [],
            'average_score' => null,
            'counts' => ['not_started' => 1, 'in_progress' => 0, 'completed' => 0, 'overdue' => 0],
        ])
        ->and($result['content'][0]['text'])->toBe('Sara Lindqvist is in 2 of your groups. '
            .'Cardiology Group A: behind (Average question set score 42.5 is below 50 (scores: 45, 40)); 3 completed, 0 in progress, 1 not started, 0 overdue. '
            .'Cardiology Group B: on track; 0 completed, 0 in progress, 1 not started, 0 overdue.');
});

test('by a unique name, in any case: the same answer as by id', function () {
    $fixture = cardiologyProgress();

    actingAsEducator($fixture['educator']);
    $byId = callTool('get_learner_summary', ['learner_id' => $fixture['sara']->id])->json('result');
    $byName = callTool('get_learner_summary', ['name' => 'LINDQ'])->json('result');

    expect($byName['isError'])->toBeFalse()
        ->and($byName['structuredContent'])->toBe($byId['structuredContent'])
        ->and($byName['content'])->toBe($byId['content']);
});

test('by an ambiguous name: the candidates with their group names, no learner, and the contract text', function () use ($groupB) {
    $fixture = cardiologyProgress();
    $groupB = $groupB($fixture['group'], $fixture['sara']);
    $groupB->educators()->attach($fixture['educator']);
    $okafor = learnerIn($groupB);
    $okafor->update(['name' => 'Sara Okafor']);
    $foreign = Group::factory()->create(['institution_id' => $groupB->institution_id]);
    educatorOf($foreign);
    learnerIn($foreign)->update(['name' => 'Sara Outsider']);

    actingAsEducator($fixture['educator']);
    $result = callTool('get_learner_summary', ['name' => 'sara'])->json('result');

    expect($result['isError'])->toBeFalse()
        ->and($result['structuredContent'])->toBe([
            'learner' => null,
            'groups' => [],
            'candidates' => [
                ['id' => $fixture['sara']->id, 'name' => 'Sara Lindqvist', 'groups' => ['Cardiology Group A', 'Cardiology Group B']],
                ['id' => $okafor->id, 'name' => 'Sara Okafor', 'groups' => ['Cardiology Group B']],
            ],
        ])
        ->and($result['content'])->toBe([[
            'type' => 'text',
            'text' => '2 learners match "sara": Sara Lindqvist (Cardiology Group A, Cardiology Group B), Sara Okafor (Cardiology Group B). '
                .'Ask which one is meant, then call again with the learner_id.',
        ]]);
});

test('learner_id wins over name', function () {
    $fixture = cardiologyProgress();

    actingAsEducator($fixture['educator']);
    $result = callTool('get_learner_summary', ['learner_id' => $fixture['tom']->id, 'name' => 'Sara'])->json('result');

    expect($result['structuredContent']['learner'])->toBe(['id' => $fixture['tom']->id, 'name' => 'Tom Becker']);
});

test('a name that only matches learners outside the caller\'s groups, and another educator\'s learner id, are not found', function (Closure $arguments) {
    $fixture = cardiologyProgress();
    $foreign = Group::factory()->create(['institution_id' => $fixture['group']->institution_id]);
    educatorOf($foreign);
    $outsider = learnerIn($foreign);
    $outsider->update(['name' => 'Zed Outsider']);

    actingAsEducator($fixture['educator']);
    $result = callTool('get_learner_summary', $arguments($outsider))->json('result');

    expect($result['isError'])->toBeTrue()
        ->and($result['content'])->toBe([['type' => 'text', 'text' => 'Learner not found.']])
        ->and($result)->not->toHaveKey('structuredContent');
})->with([
    'a name' => fn (User $outsider) => ['name' => 'Zed'],
    'a learner id' => fn (User $outsider) => ['learner_id' => $outsider->id],
    'a missing learner id' => fn (User $outsider) => ['learner_id' => 999999],
]);

test('no arguments answer the exact error', function () {
    ['educator' => $educator] = cardiologyProgress();

    actingAsEducator($educator);
    $result = callTool('get_learner_summary', [])->json('result');

    expect($result['isError'])->toBeTrue()
        ->and($result['content'])->toBe([['type' => 'text', 'text' => 'Give a learner_id or a name.']]);
});

test('a name shorter than 2 characters answers a tool error', function () {
    ['educator' => $educator] = cardiologyProgress();

    actingAsEducator($educator);
    $result = callTool('get_learner_summary', ['name' => 'S'])->json('result');

    expect($result['isError'])->toBeTrue()
        ->and($result['content'])->toBe([['type' => 'text', 'text' => 'The name field must be at least 2 characters.']]);
});
