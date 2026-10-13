<?php

use App\Models\Group;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ContentSeeder;
use Database\Seeders\DemoSeeder;

use function Pest\Laravel\postJson;
use function Pest\Laravel\seed;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-09 12:20:00');
});

test('the structured content has the contract fields, every member without statuses, and the assignments', function () {
    ['group' => $group, 'educator' => $educator, 'priya' => $priya, 'sara' => $sara, 'tom' => $tom, 'assignments' => $assignments] = cardiologyProgress();

    actingAsEducator($educator);
    $result = callTool('get_group_progress', ['group_id' => $group->id])->json('result');

    expect($result['isError'])->toBeFalse()
        ->and($result['structuredContent'])->toBe([
            'group' => ['id' => $group->id, 'name' => 'Cardiology Group A', 'institution' => 'Northside Medical School'],
            'generated_at' => '2026-10-09T14:20:00+02:00',
            'learner_count' => 3,
            'behind_count' => 2,
            'learners' => [
                [
                    'id' => $priya->id, 'name' => 'Priya Nair', 'behind' => false, 'reasons' => [], 'average_score' => 90,
                    'counts' => ['not_started' => 1, 'in_progress' => 0, 'completed' => 2, 'overdue' => 0],
                ],
                [
                    'id' => $sara->id, 'name' => 'Sara Lindqvist', 'behind' => true,
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
                    'average_score' => 42.5,
                    'counts' => ['not_started' => 1, 'in_progress' => 0, 'completed' => 3, 'overdue' => 0],
                ],
                [
                    'id' => $tom->id, 'name' => 'Tom Becker', 'behind' => true,
                    'reasons' => [
                        ['kind' => 'overdue', 'assignment_id' => $assignments['ecg']->id, 'title' => 'ECG Basics', 'due_at' => '2026-10-02T23:59:59+02:00', 'days_overdue' => 7, 'text' => 'Overdue: ECG Basics, due 2026-10-02, 7 days overdue'],
                        ['kind' => 'overdue', 'assignment_id' => $assignments['arrhythmia']->id, 'title' => 'Arrhythmia Question Set', 'due_at' => '2026-10-05T23:59:59+02:00', 'days_overdue' => 4, 'text' => 'Overdue: Arrhythmia Question Set, due 2026-10-05, 4 days overdue'],
                    ],
                    'average_score' => null,
                    'counts' => ['not_started' => 1, 'in_progress' => 1, 'completed' => 0, 'overdue' => 2],
                ],
            ],
            'assignments' => [
                ['id' => $assignments['ecg']->id, 'title' => 'ECG Basics', 'topic' => 'Cardiology', 'type' => 'article', 'due_at' => '2026-10-02T23:59:59+02:00', 'scope' => 'group', 'targeted_count' => 3, 'completed_count' => 2, 'completion_rate' => 0.67, 'average_score' => null],
                ['id' => $assignments['arrhythmia']->id, 'title' => 'Arrhythmia Question Set', 'topic' => 'Cardiology', 'type' => 'question_set', 'due_at' => '2026-10-05T23:59:59+02:00', 'scope' => 'group', 'targeted_count' => 3, 'completed_count' => 2, 'completion_rate' => 0.67, 'average_score' => 67.5],
                ['id' => $assignments['heartFailure']->id, 'title' => 'Heart Failure Question Set', 'topic' => 'Cardiology', 'type' => 'question_set', 'due_at' => '2026-10-16T23:59:59+02:00', 'scope' => 'learners', 'targeted_count' => 2, 'completed_count' => 1, 'completion_rate' => 0.5, 'average_score' => 40],
                ['id' => $assignments['lipids']->id, 'title' => 'Lipid Disorders Question Set', 'topic' => 'Cardiology', 'type' => 'question_set', 'due_at' => '2026-10-20T23:59:59+02:00', 'scope' => 'learners', 'targeted_count' => 0, 'completed_count' => 0, 'completion_rate' => 0, 'average_score' => null],
                ['id' => $assignments['cardiomyopathy']->id, 'title' => 'Cardiomyopathy Question Set', 'topic' => 'Cardiology', 'type' => 'question_set', 'due_at' => '2026-10-23T23:59:59+02:00', 'scope' => 'group', 'targeted_count' => 3, 'completed_count' => 0, 'completion_rate' => 0, 'average_score' => null],
            ],
        ])
        ->and(textAsJson($result))->toBe($result['structuredContent']);
});

test('behind_count counts the behind learners, and no learner carries statuses', function () {
    ['group' => $group, 'educator' => $educator] = cardiologyProgress();

    actingAsEducator($educator);
    $result = callTool('get_group_progress', ['group_id' => $group->id])->json('result');
    $learners = $result['structuredContent']['learners'];

    expect($result['structuredContent']['behind_count'])->toBe(count(array_filter(array_column($learners, 'behind'))))
        ->and(array_filter($learners, fn (array $learner) => array_key_exists('statuses', $learner)))->toBe([]);
});

test('a group with nothing assigned answers no assignments and nobody behind', function () {
    $group = Group::factory()->create(['name' => 'Cardiology Group B']);
    $educator = educatorOf($group);
    learnerIn($group);

    actingAsEducator($educator);
    $answer = callTool('get_group_progress', ['group_id' => $group->id])->json('result.structuredContent');

    expect($answer['assignments'])->toBe([])
        ->and($answer['learner_count'])->toBe(1)
        ->and($answer['behind_count'])->toBe(0);
});

test('another educator\'s group and a missing id answer the not found error', function (Closure $groupId) {
    ['educator' => $educator] = cardiologyProgress();

    actingAsEducator($educator);
    $result = callTool('get_group_progress', ['group_id' => $groupId()])->json('result');

    expect($result['isError'])->toBeTrue()
        ->and($result['content'])->toBe([['type' => 'text', 'text' => 'Group not found.']])
        ->and($result)->not->toHaveKey('structuredContent');
})->with([
    'another educator\'s group' => fn () => tap(Group::factory()->create(), fn (Group $group) => learnerIn($group))->id,
    'a missing id' => fn () => 999999,
]);

test('a missing group_id answers a tool error', function () {
    ['educator' => $educator] = cardiologyProgress();

    actingAsEducator($educator);
    $result = callTool('get_group_progress', [])->json('result');

    expect($result['isError'])->toBeTrue()
        ->and($result['content'])->toBe([['type' => 'text', 'text' => 'The group id field is required.']]);
});

test('get_group_progress and POST /graphql report the same flags, reasons, counts and assignment numbers', function () {
    seed([ContentSeeder::class, DemoSeeder::class]);
    $educator = User::query()->where('email', 'anna.keller@example.edu')->sole();
    $group = Group::query()->where('name', 'Cardiology Group A')->sole();

    actingAsEducator($educator);
    $tool = callTool('get_group_progress', ['group_id' => $group->id])->json('result')['structuredContent'];
    $graphql = postJson('/graphql', [
        'query' => 'query ($groupId: ID!) { groupProgress(groupId: $groupId) {
            assignments { id completionRate averageScore }
            learners { learner { id } behind reasons counts { notStarted inProgress completed overdue } }
        } }',
        'variables' => ['groupId' => (string) $group->id],
    ])->assertOk()->json('data.groupProgress');

    expect(array_map(fn (array $learner) => [
        'id' => $learner['id'],
        'behind' => $learner['behind'],
        'reasons' => array_column($learner['reasons'], 'text'),
        'counts' => array_values($learner['counts']),
    ], $tool['learners']))->toBe(array_map(fn (array $learner) => [
        'id' => (int) $learner['learner']['id'],
        'behind' => $learner['behind'],
        'reasons' => $learner['reasons'],
        'counts' => array_values($learner['counts']),
    ], $graphql['learners']))
        ->and(array_map(fn (array $assignment) => [$assignment['id'], $assignment['completion_rate'], $assignment['average_score']], $tool['assignments']))
        ->toBe(array_map(fn (array $assignment) => [(int) $assignment['id'], $assignment['completionRate'], $assignment['averageScore']], $graphql['assignments']))
        ->and($tool['behind_count'])->toBeGreaterThan(0)
        ->and($tool['behind_count'])->toBeLessThan($tool['learner_count'])
        ->and(array_values(array_unique(array_merge(...array_map(fn (array $learner) => array_column($learner['reasons'], 'kind'), $tool['learners'])))))
        ->toEqualCanonicalizing(['low_average', 'overdue']);
});
