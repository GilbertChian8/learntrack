<?php

use App\Enums\AssignmentScope;
use App\Models\Assignment;
use App\Models\Group;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-09 12:20:00');
});

test('lowest completion rate first, then lowest average with nulls last; nobody-targeted assignments left out', function () {
    ['group' => $group, 'educator' => $educator, 'assignments' => $assignments] = cardiologyProgress();

    actingAsEducator($educator);
    $result = callTool('find_hardest_assignments', ['group_id' => $group->id])->json('result');

    expect($result['isError'])->toBeFalse()
        ->and($result['structuredContent'])->toBe([
            'group' => ['id' => $group->id, 'name' => 'Cardiology Group A'],
            'assignments' => [
                ['id' => $assignments['cardiomyopathy']->id, 'title' => 'Cardiomyopathy Question Set', 'topic' => 'Cardiology', 'type' => 'question_set', 'due_at' => '2026-10-23T23:59:59+02:00', 'scope' => 'group', 'targeted_count' => 3, 'completed_count' => 0, 'completion_rate' => 0, 'average_score' => null, 'reason' => '0 of 3 learners completed it (0%)'],
                ['id' => $assignments['heartFailure']->id, 'title' => 'Heart Failure Question Set', 'topic' => 'Cardiology', 'type' => 'question_set', 'due_at' => '2026-10-16T23:59:59+02:00', 'scope' => 'learners', 'targeted_count' => 2, 'completed_count' => 1, 'completion_rate' => 0.5, 'average_score' => 40, 'reason' => '1 of 2 learners completed it (50%), average score 40'],
                ['id' => $assignments['arrhythmia']->id, 'title' => 'Arrhythmia Question Set', 'topic' => 'Cardiology', 'type' => 'question_set', 'due_at' => '2026-10-05T23:59:59+02:00', 'scope' => 'group', 'targeted_count' => 3, 'completed_count' => 2, 'completion_rate' => 0.67, 'average_score' => 67.5, 'reason' => '2 of 3 learners completed it (67%), average score 67.5'],
                ['id' => $assignments['ecg']->id, 'title' => 'ECG Basics', 'topic' => 'Cardiology', 'type' => 'article', 'due_at' => '2026-10-02T23:59:59+02:00', 'scope' => 'group', 'targeted_count' => 3, 'completed_count' => 2, 'completion_rate' => 0.67, 'average_score' => null, 'reason' => '2 of 3 learners completed it (67%)'],
            ],
        ])
        ->and(textAsJson($result))->toBe($result['structuredContent']);
});

test('limit caps the list', function () {
    ['group' => $group, 'educator' => $educator, 'assignments' => $assignments] = cardiologyProgress();

    actingAsEducator($educator);
    $result = callTool('find_hardest_assignments', ['group_id' => $group->id, 'limit' => 2])->json('result');

    expect(array_column($result['structuredContent']['assignments'], 'id'))->toBe([$assignments['cardiomyopathy']->id, $assignments['heartFailure']->id])
        ->and(array_column($result['structuredContent']['assignments'], 'reason'))->toBe([
            '0 of 3 learners completed it (0%)',
            '1 of 2 learners completed it (50%), average score 40',
        ]);
});

test('limit 0 and 21 answer a tool error', function (int $limit, string $message) {
    ['group' => $group, 'educator' => $educator] = cardiologyProgress();

    actingAsEducator($educator);
    $result = callTool('find_hardest_assignments', ['group_id' => $group->id, 'limit' => $limit])->json('result');

    expect($result['isError'])->toBeTrue()
        ->and($result['content'])->toBe([['type' => 'text', 'text' => $message]]);
})->with([
    [0, 'The limit field must be at least 1.'],
    [21, 'The limit field must not be greater than 20.'],
]);

test('a group with no assignment that targets a learner gives an empty list', function (Closure $assign) {
    $group = Group::factory()->create(['name' => 'Cardiology Group B']);
    $educator = educatorOf($group);
    learnerIn($group);
    $assign($group);

    actingAsEducator($educator);
    $result = callTool('find_hardest_assignments', ['group_id' => $group->id])->json('result');

    expect($result['structuredContent'])->toBe(['group' => ['id' => $group->id, 'name' => 'Cardiology Group B'], 'assignments' => []])
        ->and(textAsJson($result))->toBe($result['structuredContent']);
})->with([
    'no active assignment' => fn (Group $group) => Assignment::factory()->for($group)->removed()->create(),
    'one that targets nobody' => fn (Group $group) => Assignment::factory()->for($group)->create(['scope' => AssignmentScope::Learners]),
]);

test('another educator\'s group answers the not found error', function () {
    ['educator' => $educator] = cardiologyProgress();
    $foreign = Group::factory()->create();
    educatorOf($foreign);
    learnerIn($foreign);

    actingAsEducator($educator);
    $result = callTool('find_hardest_assignments', ['group_id' => $foreign->id])->json('result');

    expect($result['isError'])->toBeTrue()
        ->and($result['content'])->toBe([['type' => 'text', 'text' => 'Group not found.']])
        ->and($result)->not->toHaveKey('structuredContent');
});
