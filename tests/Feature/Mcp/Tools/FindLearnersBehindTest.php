<?php

use App\Models\Assignment;
use App\Models\Group;
use App\Queries\BehindRule;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-09 12:20:00');
});

test('only the learners with reasons, with the rule, the threshold and the contract text', function () {
    ['group' => $group, 'educator' => $educator, 'sara' => $sara, 'tom' => $tom, 'assignments' => $assignments] = cardiologyProgress();

    $result = callTool($educator, 'find_learners_behind', ['group_id' => $group->id]);

    expect($result['isError'])->toBeFalse()
        ->and($result['structuredContent'])->toBe([
            'group' => ['id' => $group->id, 'name' => 'Cardiology Group A'],
            'rule' => BehindRule::ruleText(),
            'threshold' => 50,
            'learner_count' => 3,
            'behind_count' => 2,
            'learners' => [
                [
                    'id' => $sara->id, 'name' => 'Sara Lindqvist',
                    'reasons' => [[
                        'kind' => 'low_average',
                        'average_score' => 42.5,
                        'threshold' => 50,
                        'scores' => [
                            ['assignment_id' => $assignments['arrhythmia']->id, 'title' => 'Arrhythmia Question Set', 'score' => 45],
                            ['assignment_id' => $assignments['heartFailure']->id, 'title' => 'Heart Failure Question Set', 'score' => 40],
                        ],
                        'text' => 'Average question set score 42.5 is below 50 (scores: 45, 40)',
                    ]],
                ],
                [
                    'id' => $tom->id, 'name' => 'Tom Becker',
                    'reasons' => [
                        ['kind' => 'overdue', 'assignment_id' => $assignments['ecg']->id, 'title' => 'ECG Basics', 'due_at' => '2026-10-02T23:59:59+02:00', 'days_overdue' => 7, 'text' => 'Overdue: ECG Basics, due 2026-10-02, 7 days overdue'],
                        ['kind' => 'overdue', 'assignment_id' => $assignments['arrhythmia']->id, 'title' => 'Arrhythmia Question Set', 'due_at' => '2026-10-05T23:59:59+02:00', 'days_overdue' => 4, 'text' => 'Overdue: Arrhythmia Question Set, due 2026-10-05, 4 days overdue'],
                    ],
                ],
            ],
        ])
        ->and($result['content'])->toBe([[
            'type' => 'text',
            'text' => '2 of 3 learners in Cardiology Group A are behind. '
                .'Sara Lindqvist: Average question set score 42.5 is below 50 (scores: 45, 40). '
                .'Tom Becker: Overdue: ECG Basics, due 2026-10-02, 7 days overdue; Overdue: Arrhythmia Question Set, due 2026-10-05, 4 days overdue.',
        ]]);
});

test('one learner behind takes the singular verb', function () {
    $group = Group::factory()->create(['name' => 'Cardiology Group B']);
    $educator = educatorOf($group);
    $learner = learnerIn($group);
    learnerIn($group);
    Assignment::factory()->for($group)->forLearners([$learner])->create(['due_at' => '2026-10-08 21:59:59']);

    expect(callTool($educator, 'find_learners_behind', ['group_id' => $group->id])['content'][0]['text'])
        ->toStartWith('1 of 2 learners in Cardiology Group B is behind. ');
});

test('a group with nobody behind gives an empty list and the Nobody text', function () {
    $group = Group::factory()->create(['name' => 'Cardiology Group B']);
    $educator = educatorOf($group);
    learnerIn($group);
    learnerIn($group);
    Assignment::factory()->for($group)->create(['due_at' => '2026-10-16 21:59:59']);

    $result = callTool($educator, 'find_learners_behind', ['group_id' => $group->id]);

    expect($result['structuredContent']['learners'])->toBe([])
        ->and($result['structuredContent']['behind_count'])->toBe(0)
        ->and($result['structuredContent']['learner_count'])->toBe(2)
        ->and($result['content'])->toBe([['type' => 'text', 'text' => 'Nobody in Cardiology Group B is behind (2 learners).']]);
});

test('another educator\'s group answers the not found error', function () {
    ['educator' => $educator] = cardiologyProgress();
    $foreign = Group::factory()->create();
    educatorOf($foreign);
    learnerIn($foreign);

    $result = callTool($educator, 'find_learners_behind', ['group_id' => $foreign->id]);

    expect($result['isError'])->toBeTrue()
        ->and($result['content'])->toBe([['type' => 'text', 'text' => 'Group not found.']])
        ->and($result)->not->toHaveKey('structuredContent');
});
