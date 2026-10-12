<?php

use App\Enums\AuditAction;
use App\Enums\AuditChannel;
use App\Models\Assignment;
use App\Models\AuditEntry;
use App\Models\Group;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-09 12:00:00');
});

test('tools/list publishes change_due_date with the contract input schema, the write hints and an output schema', function () {
    actingAsEducator();

    $tool = listedTool('change_due_date');

    expect($tool['title'])->toBe('Change due date')
        ->and($tool['description'])->toBe('Moves the due date of an assignment in one of your groups. Get the assignment_id from get_group_progress, find_hardest_assignments or assign_content. The same date answers changed: false. Nothing else about the assignment changes and no progress is touched.')
        ->and($tool['annotations'])->toBe(['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true])
        ->and($tool['inputSchema'])->toEqual(json_decode(<<<'JSON'
            {
              "type": "object",
              "properties": {
                "assignment_id": { "type": "integer", "description": "The assignment id from an earlier answer." },
                "due_date": { "type": "string", "description": "YYYY-MM-DD (end of that day in the institution's time zone) or an ISO 8601 date-time with offset. Must be in the future." }
              },
              "required": ["assignment_id", "due_date"],
              "additionalProperties": false
            }
            JSON, true))
        ->and($tool['outputSchema']['required'])->toBe(['changed', 'assignment', 'previous_due_at', 'due_at', 'message']);
});

test('the example in mcp-tools.md matches the published output schema', function () {
    actingAsEducator();

    $example = json_decode(<<<'JSON'
        {
          "changed": true,
          "assignment": { "id": 41, "group_id": 12, "content": { "id": 8, "title": "Heart Failure Question Set", "topic": "Cardiology", "type": "question_set", "estimated_minutes": 30 }, "due_at": "2026-10-23T23:59:59+02:00", "scope": "learners", "targeted_count": 2, "learners": [ { "id": 101, "name": "Sara Lindqvist" }, { "id": 102, "name": "Tom Becker" } ], "created_at": "2026-10-09T14:03:21+02:00" },
          "previous_due_at": "2026-10-16T23:59:59+02:00",
          "due_at": "2026-10-23T23:59:59+02:00",
          "message": "Moved Heart Failure Question Set in Cardiology Group A from 2026-10-16T23:59:59+02:00 to 2026-10-23T23:59:59+02:00. 2 learners are affected."
        }
        JSON, true);

    expect(schemaErrors(listedTool('change_due_date')['outputSchema'], $example))->toBe([]);
});

test('a new date moves the assignment, counts the affected learners and writes one mcp audit row', function () {
    [$group, $educator, $content, $priya, $sara] = cardiologyGroup();
    $assignment = assignHeartFailure($group, $educator, $content, [$sara, $priya]);
    actingAsEducator($educator);

    $message = 'Moved Heart Failure Question Set in Cardiology Group A from 2026-10-16T23:59:59+02:00 to 2026-10-23T23:59:59+02:00. 2 learners are affected.';

    callTool('change_due_date', ['assignment_id' => $assignment->id, 'due_date' => '2026-10-23'])
        ->assertJsonPath('result.isError', false)
        ->assertJsonPath('result.content', [['type' => 'text', 'text' => $message]])
        ->assertJsonPath('result.structuredContent', [
            'changed' => true,
            'assignment' => heartFailureAssignment($assignment, [learnerItem($priya), learnerItem($sara)], '2026-10-23T23:59:59+02:00'),
            'previous_due_at' => '2026-10-16T23:59:59+02:00',
            'due_at' => '2026-10-23T23:59:59+02:00',
            'message' => $message,
        ]);

    $audit = AuditEntry::query()->sole();

    expect($assignment->fresh()?->due_at->toIso8601ZuluString())->toBe('2026-10-23T21:59:59Z')
        ->and($audit->action)->toBe(AuditAction::AssignmentDueChanged)
        ->and($audit->channel)->toBe(AuditChannel::Mcp)
        ->and($audit->actor_id)->toBe($educator->id)
        ->and($audit->subject_id)->toBe($assignment->id)
        ->and($audit->changes)->toEqual(['due_at' => ['from' => '2026-10-16T21:59:59Z', 'to' => '2026-10-23T21:59:59Z']]);
});

test('the affected learners sentence counts the targeted learners', function (bool $wholeGroup, string $sentence) {
    [$group, $educator, $content, , $sara] = cardiologyGroup();
    $assignment = assignHeartFailure($group, $educator, $content, $wholeGroup ? [] : [$sara]);
    actingAsEducator($educator);

    callTool('change_due_date', ['assignment_id' => $assignment->id, 'due_date' => '2026-10-23'])
        ->assertJsonPath('result.content.0.text', "Moved Heart Failure Question Set in Cardiology Group A from 2026-10-16T23:59:59+02:00 to 2026-10-23T23:59:59+02:00. {$sentence}");
})->with([
    'one learner' => [false, '1 learner is affected.'],
    'the whole group' => [true, '3 learners are affected.'],
]);

test('the same due date answers changed false and writes nothing', function (string $dueDate) {
    [$group, $educator, $content] = cardiologyGroup();
    $assignment = assignHeartFailure($group, $educator, $content);
    actingAsEducator($educator);

    $message = 'Heart Failure Question Set in Cardiology Group A is already due 2026-10-16T23:59:59+02:00. Nothing changed.';

    callTool('change_due_date', ['assignment_id' => $assignment->id, 'due_date' => $dueDate])
        ->assertJsonPath('result.isError', false)
        ->assertJsonPath('result.content', [['type' => 'text', 'text' => $message]])
        ->assertJsonPath('result.structuredContent', [
            'changed' => false,
            'assignment' => heartFailureAssignment($assignment),
            'previous_due_at' => '2026-10-16T23:59:59+02:00',
            'due_at' => '2026-10-16T23:59:59+02:00',
            'message' => $message,
        ]);

    expect(AuditEntry::query()->count())->toBe(0);
})->with([
    'as a date' => '2026-10-16',
    'as the same instant in UTC' => '2026-10-16T21:59:59Z',
]);

test('a bad call is a tool error with the exact message, and nothing is written', function (Closure $case) {
    [$group, $educator, $content] = cardiologyGroup();
    $assignment = assignHeartFailure($group, $educator, $content);
    actingAsEducator($educator);

    [$arguments, $message] = $case($assignment);

    callTool('change_due_date', $arguments)
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content', [['type' => 'text', 'text' => $message]])
        ->assertJsonMissingPath('result.structuredContent');

    expect($assignment->fresh()?->due_at->toIso8601ZuluString())->toBe('2026-10-16T21:59:59Z')
        ->and(AuditEntry::query()->count())->toBe(0);
})->with([
    'a due date in the past' => fn (Assignment $assignment) => [
        ['assignment_id' => $assignment->id, 'due_date' => '2026-10-09T13:59:59+02:00'],
        'The due date must be in the future. Today is 2026-10-09 in Europe/Berlin.',
    ],
    'a due date that is not a date' => fn (Assignment $assignment) => [
        ['assignment_id' => $assignment->id, 'due_date' => 'next friday'],
        'The due date "next friday" is not a date. Use YYYY-MM-DD or an ISO 8601 date-time.',
    ],
    'a removed assignment' => fn (Assignment $assignment) => [
        ['assignment_id' => Assignment::factory()->for($assignment->group)->removed()->create()->id, 'due_date' => '2026-10-23'],
        'Assignment not found.',
    ],
    'another educator\'s assignment' => fn () => [
        ['assignment_id' => Assignment::factory()->create()->id, 'due_date' => '2026-10-23'],
        'Assignment not found.',
    ],
    'an assignment in a group of the same institution taught by another educator' => fn (Assignment $assignment) => [
        ['assignment_id' => Assignment::factory()->for(tap(Group::factory()->for($assignment->group->institution)->create(), educatorOf(...)))->create()->id, 'due_date' => '2026-10-23'],
        'Assignment not found.',
    ],
    'an assignment that does not exist' => fn () => [
        ['assignment_id' => 999999, 'due_date' => '2026-10-23'],
        'Assignment not found.',
    ],
    'no assignment_id' => fn () => [
        ['due_date' => '2026-10-23'],
        'The assignment id field is required.',
    ],
    'an assignment_id that is a string' => fn (Assignment $assignment) => [
        ['assignment_id' => (string) $assignment->id, 'due_date' => '2026-10-23'],
        'The assignment id field must be an integer.',
    ],
    'an argument the schema does not name' => fn (Assignment $assignment) => [
        ['assignment_id' => $assignment->id, 'due_date' => '2026-10-23', 'dueDate' => '2026-10-30'],
        'The argument "dueDate" is not in the input schema.',
    ],
]);

test('no answer has an email key or an @ in its text', function () {
    [$group, $educator, $content, $priya, $sara] = cardiologyGroup();
    $assignment = assignHeartFailure($group, $educator, $content, [$priya, $sara]);
    actingAsEducator($educator);
    $outputSchema = listedTool('change_due_date')['outputSchema'];

    foreach (['2026-10-23', '2026-10-23'] as $dueDate) {
        $result = callTool('change_due_date', ['assignment_id' => $assignment->id, 'due_date' => $dueDate])
            ->assertJsonPath('result.isError', false)
            ->json('result');

        expect(keysNamed('email', $result['structuredContent']))->toBe([])
            ->and($result['content'][0]['text'])->not->toContain('@')
            ->and(schemaErrors($outputSchema, $result['structuredContent']))->toBe([]);
    }
});
