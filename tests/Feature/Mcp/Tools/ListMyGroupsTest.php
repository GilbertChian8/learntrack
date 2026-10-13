<?php

use App\Models\Assignment;
use App\Models\Group;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-09 12:20:00');
});

test('only the caller\'s groups, ordered by name, with their counts, and the same object as the text', function () {
    ['group' => $groupA, 'educator' => $educator] = cardiologyProgress();
    $groupB = Group::factory()->create(['institution_id' => $groupA->institution_id, 'name' => 'Anatomy Group A']);
    $groupB->educators()->attach($educator);
    learnerIn($groupB);
    Assignment::factory()->for($groupB)->create();
    Assignment::factory()->for($groupB)->removed()->create();
    $foreign = Group::factory()->create(['institution_id' => $groupA->institution_id, 'name' => 'Anatomy Group 0']);
    educatorOf($foreign);
    learnerIn($foreign);

    actingAsEducator($educator);
    $result = callTool('list_my_groups', [])->json('result');

    expect($result['isError'])->toBeFalse()
        ->and($result['structuredContent'])->toBe([
            'groups' => [
                ['id' => $groupB->id, 'name' => 'Anatomy Group A', 'institution' => 'Northside Medical School', 'learner_count' => 1, 'assignment_count' => 1],
                ['id' => $groupA->id, 'name' => 'Cardiology Group A', 'institution' => 'Northside Medical School', 'learner_count' => 3, 'assignment_count' => 5],
            ],
        ])
        ->and(textAsJson($result))->toBe($result['structuredContent']);
});

test('an educator with no groups gets an empty list', function () {
    cardiologyProgress();
    $educator = User::factory()->educator()->create();

    actingAsEducator($educator);
    $result = callTool('list_my_groups', [])->json('result');

    expect($result['isError'])->toBeFalse()
        ->and($result['structuredContent'])->toBe(['groups' => []])
        ->and(textAsJson($result))->toBe(['groups' => []]);
});
