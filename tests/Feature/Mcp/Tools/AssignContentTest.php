<?php

use App\Enums\AuditAction;
use App\Enums\AuditChannel;
use App\Enums\ContentType;
use App\Models\Assignment;
use App\Models\AuditEntry;
use App\Models\ContentItem;
use App\Models\Group;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-09 12:00:00');
});

test('tools/list publishes assign_content with the contract input schema, the write hints and an output schema', function () {
    actingAsEducator();

    $tool = listedTool('assign_content');

    expect($tool['title'])->toBe('Assign content')
        ->and($tool['description'])->toBe('Assigns a content item (an article or a question set) to one of your groups with a due date, or only to some learners in it (for example the learners who are behind). Give content_item_id when you know it; otherwise give content_title and the tool finds the item, or returns the candidates when several match. Nothing is ever deleted. Repeating the same call changes nothing and says so. If the content is already assigned with another due date, nothing changes; use change_due_date instead.')
        ->and($tool['annotations'])->toBe(['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true])
        ->and($tool['inputSchema'])->toEqual(json_decode(<<<'JSON'
            {
              "type": "object",
              "properties": {
                "group_id": { "type": "integer", "description": "The group id from list_my_groups." },
                "content_item_id": { "type": "integer", "description": "The content item id. Preferred when known." },
                "content_title": { "type": "string", "minLength": 2, "description": "Part of the content title, when the id is unknown. Case-insensitive." },
                "due_date": { "type": "string", "description": "YYYY-MM-DD (end of that day in the institution's time zone) or an ISO 8601 date-time with offset. Must be in the future." },
                "learner_ids": { "type": "array", "items": { "type": "integer" }, "minItems": 1, "maxItems": 60, "uniqueItems": true, "description": "Only these learners get the assignment. Leave out to assign to the whole group." }
              },
              "required": ["group_id", "due_date"],
              "additionalProperties": false
            }
            JSON, true))
        ->and($tool['outputSchema']['required'])->toBe(['changed', 'result', 'assignment', 'added', 'already_had_it', 'candidates', 'message']);
});

test('the example in mcp-tools.md matches the published output schema', function () {
    actingAsEducator();

    $example = json_decode(<<<'JSON'
        {
          "changed": true,
          "result": "created",
          "assignment": {
            "id": 41,
            "group_id": 12,
            "content": { "id": 8, "title": "Heart Failure Question Set", "topic": "Cardiology", "type": "question_set", "estimated_minutes": 30 },
            "due_at": "2026-10-16T23:59:59+02:00",
            "scope": "learners",
            "targeted_count": 2,
            "learners": [ { "id": 101, "name": "Sara Lindqvist" }, { "id": 102, "name": "Tom Becker" } ],
            "created_at": "2026-10-09T14:03:21+02:00"
          },
          "added": [ { "id": 101, "name": "Sara Lindqvist" }, { "id": 102, "name": "Tom Becker" } ],
          "already_had_it": [],
          "candidates": [],
          "message": "Assigned Heart Failure Question Set to Cardiology Group A, due 2026-10-16T23:59:59+02:00, for 2 learners: Sara Lindqvist, Tom Becker."
        }
        JSON, true);

    expect(schemaErrors(listedTool('assign_content')['outputSchema'], $example))->toBe([]);
});

test('a new assignment for the whole group answers created, lists every member and writes one mcp audit row', function (Closure $content) {
    [$group, $educator, $heartFailure, $priya, $sara, $tom] = cardiologyGroup();
    actingAsEducator($educator);

    $response = callTool('assign_content', ['group_id' => $group->id, ...$content($heartFailure), 'due_date' => '2026-10-16']);

    $assignment = Assignment::query()->sole();
    $audit = AuditEntry::query()->sole();
    $message = 'Assigned Heart Failure Question Set to Cardiology Group A, due 2026-10-16T23:59:59+02:00, for all 3 learners.';

    $response->assertJsonPath('result.isError', false)
        ->assertJsonPath('result.content', [['type' => 'text', 'text' => $message]])
        ->assertJsonPath('result.structuredContent', [
            'changed' => true,
            'result' => 'created',
            'assignment' => heartFailureAssignment($assignment),
            'added' => [learnerItem($priya), learnerItem($sara), learnerItem($tom)],
            'already_had_it' => [],
            'candidates' => [],
            'message' => $message,
        ]);

    expect($assignment->content_item_id)->toBe($heartFailure->id)
        ->and($audit->channel)->toBe(AuditChannel::Mcp)
        ->and($audit->action)->toBe(AuditAction::AssignmentCreated)
        ->and($audit->actor_id)->toBe($educator->id)
        ->and($audit->subject_id)->toBe($assignment->id)
        ->and($audit->changes)->toEqual([
            'content_item_id' => $heartFailure->id,
            'due_at' => '2026-10-16T21:59:59Z',
            'scope' => 'group',
            'learner_ids' => [],
        ]);
})->with([
    'by content_item_id' => fn (ContentItem $item) => ['content_item_id' => $item->id],
    'by a content_title matching one item' => fn (ContentItem $item) => ['content_title' => 'failure QUESTION'],
    'by content_item_id, which wins over content_title' => fn (ContentItem $item) => ['content_item_id' => $item->id, 'content_title' => 'no such title'],
]);

test('the same call again answers unchanged, and there is still one assignment and one audit row', function () {
    [$group, $educator, $content, $priya, $sara, $tom] = cardiologyGroup();
    actingAsEducator($educator);
    $arguments = ['group_id' => $group->id, 'content_item_id' => $content->id, 'due_date' => '2026-10-16'];
    callTool('assign_content', $arguments);

    $message = 'Heart Failure Question Set is already assigned to Cardiology Group A, due 2026-10-16T23:59:59+02:00, and the whole group already has it. Nothing changed.';

    callTool('assign_content', $arguments)
        ->assertJsonPath('result.isError', false)
        ->assertJsonPath('result.content', [['type' => 'text', 'text' => $message]])
        ->assertJsonPath('result.structuredContent', [
            'changed' => false,
            'result' => 'unchanged',
            'assignment' => heartFailureAssignment(Assignment::query()->sole()),
            'added' => [],
            'already_had_it' => [learnerItem($priya), learnerItem($sara), learnerItem($tom)],
            'candidates' => [],
            'message' => $message,
        ]);

    expect(Assignment::query()->count())->toBe(1)
        ->and(AuditEntry::query()->count())->toBe(1);
});

test('learner_ids create a learners assignment; one more id adds only that learner with an mcp audit row', function () {
    [$group, $educator, $content, $priya, $sara, $tom] = cardiologyGroup();
    actingAsEducator($educator);

    $created = 'Assigned Heart Failure Question Set to Cardiology Group A, due 2026-10-16T23:59:59+02:00, for 2 learners: Sara Lindqvist, Tom Becker.';
    callTool('assign_content', ['group_id' => $group->id, 'content_item_id' => $content->id, 'due_date' => '2026-10-16', 'learner_ids' => [$tom->id, $sara->id]])
        ->assertJsonPath('result.content', [['type' => 'text', 'text' => $created]])
        ->assertJsonPath('result.structuredContent.result', 'created')
        ->assertJsonPath('result.structuredContent.assignment.scope', 'learners')
        ->assertJsonPath('result.structuredContent.added', [learnerItem($sara), learnerItem($tom)]);

    $added = 'Heart Failure Question Set was already assigned in Cardiology Group A, due 2026-10-16T23:59:59+02:00. Added 1 learner: Priya Nair. 2 learners already had it: Sara Lindqvist, Tom Becker.';
    callTool('assign_content', ['group_id' => $group->id, 'content_item_id' => $content->id, 'due_date' => '2026-10-16', 'learner_ids' => [$tom->id, $sara->id, $priya->id]])
        ->assertJsonPath('result.content', [['type' => 'text', 'text' => $added]])
        ->assertJsonPath('result.structuredContent', [
            'changed' => true,
            'result' => 'learners_added',
            'assignment' => heartFailureAssignment(Assignment::query()->sole(), [learnerItem($priya), learnerItem($sara), learnerItem($tom)]),
            'added' => [learnerItem($priya)],
            'already_had_it' => [learnerItem($sara), learnerItem($tom)],
            'candidates' => [],
            'message' => $added,
        ]);

    $audit = AuditEntry::query()->latest('id')->first();

    expect(AuditEntry::query()->count())->toBe(2)
        ->and($audit?->action)->toBe(AuditAction::AssignmentLearnersAdded)
        ->and($audit?->channel)->toBe(AuditChannel::Mcp)
        ->and($audit?->changes)->toEqual(['learner_ids' => [$priya->id]]);
});

test('when none of the named learners had it, the message leaves out the already-had-it sentence', function () {
    [$group, $educator, $content, $priya, $sara] = cardiologyGroup();
    actingAsEducator($educator);
    callTool('assign_content', ['group_id' => $group->id, 'content_item_id' => $content->id, 'due_date' => '2026-10-16', 'learner_ids' => [$sara->id]]);

    callTool('assign_content', ['group_id' => $group->id, 'content_item_id' => $content->id, 'due_date' => '2026-10-16', 'learner_ids' => [$priya->id]])
        ->assertJsonPath('result.content.0.text', 'Heart Failure Question Set was already assigned in Cardiology Group A, due 2026-10-16T23:59:59+02:00. Added 1 learner: Priya Nair.')
        ->assertJsonPath('result.structuredContent.result', 'learners_added')
        ->assertJsonPath('result.structuredContent.added', [learnerItem($priya)])
        ->assertJsonPath('result.structuredContent.already_had_it', []);
});

test('the same learner_ids again answer unchanged with the named-learners message', function () {
    [$group, $educator, $content, , $sara, $tom] = cardiologyGroup();
    actingAsEducator($educator);
    $arguments = ['group_id' => $group->id, 'content_item_id' => $content->id, 'due_date' => '2026-10-16', 'learner_ids' => [$sara->id, $tom->id]];
    callTool('assign_content', $arguments);

    callTool('assign_content', $arguments)
        ->assertJsonPath('result.content.0.text', 'Heart Failure Question Set is already assigned to Cardiology Group A, due 2026-10-16T23:59:59+02:00, and every learner you named already has it. Nothing changed.')
        ->assertJsonPath('result.structuredContent.changed', false)
        ->assertJsonPath('result.structuredContent.result', 'unchanged')
        ->assertJsonPath('result.structuredContent.added', [])
        ->assertJsonPath('result.structuredContent.already_had_it', [learnerItem($sara), learnerItem($tom)]);

    expect(AuditEntry::query()->count())->toBe(1);
});

test('no learner_ids widen a learners assignment to the whole group', function (bool $targetEveryone, int $newMembers, string $message) {
    [$group, $educator, $content, $priya, $sara, $tom] = cardiologyGroup();
    for ($i = 1; $i <= $newMembers; $i++) {
        $group->learners()->attach(User::factory()->learner()->create(['institution_id' => $group->institution_id, 'name' => sprintf('Zoe %02d', $i)]));
    }
    actingAsEducator($educator);
    $learnerIds = $targetEveryone ? [$priya->id, $sara->id, $tom->id] : [$sara->id];
    callTool('assign_content', ['group_id' => $group->id, 'content_item_id' => $content->id, 'due_date' => '2026-10-16', 'learner_ids' => $learnerIds]);

    callTool('assign_content', ['group_id' => $group->id, 'content_item_id' => $content->id, 'due_date' => '2026-10-16'])
        ->assertJsonPath('result.content.0.text', $message)
        ->assertJsonPath('result.structuredContent.changed', true)
        ->assertJsonPath('result.structuredContent.result', 'learners_added')
        ->assertJsonPath('result.structuredContent.assignment.scope', 'group')
        ->assertJsonPath('result.structuredContent.assignment.learners', null)
        ->assertJsonPath('result.structuredContent.message', $message);

    $audit = AuditEntry::query()->latest('id')->first();

    expect($audit?->action)->toBe(AuditAction::AssignmentLearnersAdded)
        ->and($audit?->channel)->toBe(AuditChannel::Mcp)
        ->and($audit?->changes['scope'] ?? null)->toEqual(['from' => 'learners', 'to' => 'group']);
})->with([
    'nobody added' => [true, 0, 'Heart Failure Question Set was already assigned in Cardiology Group A to 3 learners, due 2026-10-16T23:59:59+02:00. Now assigned to the whole group, including learners who join later. Every current learner already had it.'],
    'ten added, named' => [false, 8, 'Heart Failure Question Set was already assigned in Cardiology Group A to 1 learner, due 2026-10-16T23:59:59+02:00. Now assigned to the whole group, including learners who join later. Added 10 learners: Priya Nair, Tom Becker, Zoe 01, Zoe 02, Zoe 03, Zoe 04, Zoe 05, Zoe 06, Zoe 07, Zoe 08.'],
    'eleven added, counted only' => [false, 9, 'Heart Failure Question Set was already assigned in Cardiology Group A to 1 learner, due 2026-10-16T23:59:59+02:00. Now assigned to the whole group, including learners who join later. Added 11 learners.'],
]);

test('another due date for assigned content answers already_assigned with the existing assignment and writes nothing', function () {
    [$group, $educator, $content] = cardiologyGroup();
    $existing = assignHeartFailure($group, $educator, $content);
    actingAsEducator($educator);

    $message = "Heart Failure Question Set is already assigned to Cardiology Group A, due 2026-10-16T23:59:59+02:00, not 2026-10-23. Nothing changed. To move it, call change_due_date with assignment_id {$existing->id}.";

    callTool('assign_content', ['group_id' => $group->id, 'content_item_id' => $content->id, 'due_date' => '2026-10-23'])
        ->assertJsonPath('result.isError', false)
        ->assertJsonPath('result.content', [['type' => 'text', 'text' => $message]])
        ->assertJsonPath('result.structuredContent', [
            'changed' => false,
            'result' => 'already_assigned',
            'assignment' => heartFailureAssignment($existing),
            'added' => [],
            'already_had_it' => [],
            'candidates' => [],
            'message' => $message,
        ]);

    expect($existing->fresh()?->due_at->toIso8601ZuluString())->toBe('2026-10-16T21:59:59Z')
        ->and(Assignment::query()->count())->toBe(1)
        ->and(AuditEntry::query()->count())->toBe(0);
});

test('a content_title matching several items answers the candidates and writes nothing', function () {
    [$group, $educator, $heartFailure] = cardiologyGroup();
    $sounds = ContentItem::factory()->create(['title' => 'Heart Sounds', 'topic' => 'Cardiology', 'type' => ContentType::Article]);
    $congenital = ContentItem::factory()->create(['title' => 'Congenital Heart Disease', 'topic' => 'Pediatrics', 'type' => ContentType::Article]);
    ContentItem::factory()->create(['title' => 'ECG Basics']);
    actingAsEducator($educator);

    $message = '3 content items match "heart": Congenital Heart Disease (article, Pediatrics), Heart Failure Question Set (question_set, Cardiology), Heart Sounds (article, Cardiology). Ask which one is meant, then call again with content_item_id.';

    callTool('assign_content', ['group_id' => $group->id, 'content_title' => 'heart', 'due_date' => '2026-10-16'])
        ->assertJsonPath('result.isError', false)
        ->assertJsonPath('result.content', [['type' => 'text', 'text' => $message]])
        ->assertJsonPath('result.structuredContent', [
            'changed' => false,
            'result' => 'candidates',
            'assignment' => null,
            'added' => [],
            'already_had_it' => [],
            'candidates' => [
                ['id' => $congenital->id, 'title' => 'Congenital Heart Disease', 'topic' => 'Pediatrics', 'type' => 'article'],
                ['id' => $heartFailure->id, 'title' => 'Heart Failure Question Set', 'topic' => 'Cardiology', 'type' => 'question_set'],
                ['id' => $sounds->id, 'title' => 'Heart Sounds', 'topic' => 'Cardiology', 'type' => 'article'],
            ],
            'message' => $message,
        ]);

    expect(Assignment::query()->count())->toBe(0)
        ->and(AuditEntry::query()->count())->toBe(0);
});

test('a bad call is a tool error with the exact message, and nothing is written', function (Closure $case) {
    [$group, $educator, $content, $priya] = cardiologyGroup();
    $outsider = User::factory()->learner()->create(['institution_id' => $group->institution_id]);
    $otherOutsider = User::factory()->learner()->create(['institution_id' => $group->institution_id]);
    actingAsEducator($educator);

    [$arguments, $message] = $case($group, $content, [$priya, $outsider, $otherOutsider]);

    callTool('assign_content', $arguments)
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content', [['type' => 'text', 'text' => $message]])
        ->assertJsonMissingPath('result.structuredContent');

    expect(Assignment::query()->count())->toBe(0)
        ->and(DB::table('assignment_learners')->count())->toBe(0)
        ->and(AuditEntry::query()->count())->toBe(0);
})->with([
    'a due date in the past' => fn (Group $group, ContentItem $content) => [
        ['group_id' => $group->id, 'content_item_id' => $content->id, 'due_date' => '2026-10-08'],
        'The due date must be in the future. Today is 2026-10-09 in Europe/Berlin.',
    ],
    'a due date that is not a date' => fn (Group $group, ContentItem $content) => [
        ['group_id' => $group->id, 'content_item_id' => $content->id, 'due_date' => 'next friday'],
        'The due date "next friday" is not a date. Use YYYY-MM-DD or an ISO 8601 date-time.',
    ],
    'learners who are not members' => fn (Group $group, ContentItem $content, array $learners) => [
        ['group_id' => $group->id, 'content_item_id' => $content->id, 'due_date' => '2026-10-16', 'learner_ids' => array_map(fn (User $learner) => $learner->id, $learners)],
        "Learner {$learners[1]->id} is not a member of Cardiology Group A.",
    ],
    'another educator\'s group' => fn (Group $group, ContentItem $content) => [
        ['group_id' => Group::factory()->create()->id, 'content_item_id' => $content->id, 'due_date' => '2026-10-16'],
        'Group not found.',
    ],
    'a group of the same institution taught by another educator' => fn (Group $group, ContentItem $content) => [
        ['group_id' => tap(Group::factory()->for($group->institution)->create(), educatorOf(...))->id, 'content_item_id' => $content->id, 'due_date' => '2026-10-16'],
        'Group not found.',
    ],
    'a group that does not exist' => fn (Group $group, ContentItem $content) => [
        ['group_id' => 999999, 'content_item_id' => $content->id, 'due_date' => '2026-10-16'],
        'Group not found.',
    ],
    'an unknown content_item_id' => fn (Group $group, ContentItem $content) => [
        ['group_id' => $group->id, 'content_item_id' => $content->id + 1, 'due_date' => '2026-10-16'],
        'Content item not found.',
    ],
    'a content_title matching nothing' => fn (Group $group) => [
        ['group_id' => $group->id, 'content_title' => 'xyz', 'due_date' => '2026-10-16'],
        'No content item matches "xyz".',
    ],
    'neither content argument' => fn (Group $group) => [
        ['group_id' => $group->id, 'due_date' => '2026-10-16'],
        'Give a content_item_id or a content_title.',
    ],
]);

test('today in the past-date message is the institution\'s date, not the UTC date', function () {
    CarbonImmutable::setTestNow('2026-10-09 22:30:00');
    [$group, $educator, $content] = cardiologyGroup();
    actingAsEducator($educator);

    callTool('assign_content', ['group_id' => $group->id, 'content_item_id' => $content->id, 'due_date' => '2026-10-09'])
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'The due date must be in the future. Today is 2026-10-10 in Europe/Berlin.');
});

test('input that breaks the schema is a tool error with the validation message, and nothing is written', function (Closure $arguments, string $message) {
    [$group, $educator, $content] = cardiologyGroup();
    actingAsEducator($educator);

    callTool('assign_content', $arguments($group, $content))
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content', [['type' => 'text', 'text' => $message]]);

    expect(Assignment::query()->count())->toBe(0)
        ->and(AuditEntry::query()->count())->toBe(0);
})->with([
    'no group_id' => [
        fn (Group $group, ContentItem $content) => ['content_item_id' => $content->id, 'due_date' => '2026-10-16'],
        'The group id field is required.',
    ],
    'a group_id that is a string' => [
        fn (Group $group, ContentItem $content) => ['group_id' => (string) $group->id, 'content_item_id' => $content->id, 'due_date' => '2026-10-16'],
        'The group id field must be an integer.',
    ],
    'a one-letter content_title' => [
        fn (Group $group) => ['group_id' => $group->id, 'content_title' => 'h', 'due_date' => '2026-10-16'],
        'The content title field must be at least 2 characters.',
    ],
    'no due_date' => [
        fn (Group $group, ContentItem $content) => ['group_id' => $group->id, 'content_item_id' => $content->id],
        'The due date field is required.',
    ],
    'a due_date that is a number' => [
        fn (Group $group, ContentItem $content) => ['group_id' => $group->id, 'content_item_id' => $content->id, 'due_date' => 20261016],
        'The due date field must be a string.',
    ],
    'an empty learner_ids' => [
        fn (Group $group, ContentItem $content) => ['group_id' => $group->id, 'content_item_id' => $content->id, 'due_date' => '2026-10-16', 'learner_ids' => []],
        'The learner ids field must have at least 1 items.',
    ],
    'a repeated learner id' => [
        fn (Group $group, ContentItem $content) => ['group_id' => $group->id, 'content_item_id' => $content->id, 'due_date' => '2026-10-16', 'learner_ids' => [1, 1]],
        'The learner_ids.0 field has a duplicate value. The learner_ids.1 field has a duplicate value.',
    ],
    'more than 60 learner ids' => [
        fn (Group $group, ContentItem $content) => ['group_id' => $group->id, 'content_item_id' => $content->id, 'due_date' => '2026-10-16', 'learner_ids' => range(1, 61)],
        'The learner ids field must not have more than 60 items.',
    ],    'an argument the schema does not name' => [
        fn (Group $group, ContentItem $content) => ['group_id' => $group->id, 'content_item_id' => $content->id, 'due_date' => '2026-10-16', 'learnerIds' => [1]],
        'The argument "learnerIds" is not in the input schema.',
    ],
]);

test('no answer has an email key or an @ in its text', function () {
    [$group, $educator, $content, $priya, $sara] = cardiologyGroup();
    ContentItem::factory()->create(['title' => 'Heart Sounds']);
    actingAsEducator($educator);
    $outputSchema = listedTool('assign_content')['outputSchema'];

    $calls = [
        ['group_id' => $group->id, 'content_item_id' => $content->id, 'due_date' => '2026-10-16', 'learner_ids' => [$sara->id]],
        ['group_id' => $group->id, 'content_item_id' => $content->id, 'due_date' => '2026-10-16', 'learner_ids' => [$sara->id, $priya->id]],
        ['group_id' => $group->id, 'content_item_id' => $content->id, 'due_date' => '2026-10-16', 'learner_ids' => [$sara->id]],
        ['group_id' => $group->id, 'content_item_id' => $content->id, 'due_date' => '2026-10-16'],
        ['group_id' => $group->id, 'content_item_id' => $content->id, 'due_date' => '2026-10-23'],
        ['group_id' => $group->id, 'content_title' => 'heart', 'due_date' => '2026-10-16'],
    ];

    $results = collect($calls)->map(function (array $arguments) use ($outputSchema): string {
        $result = callTool('assign_content', $arguments)->assertJsonPath('result.isError', false)->json('result');

        expect(keysNamed('email', $result['structuredContent']))->toBe([])
            ->and($result['content'][0]['text'])->not->toContain('@')
            ->and(schemaErrors($outputSchema, $result['structuredContent']))->toBe([]);

        return $result['structuredContent']['result'];
    });

    expect($results->all())->toBe(['created', 'learners_added', 'unchanged', 'learners_added', 'already_assigned', 'candidates']);
});

test('REST and the tools write the same assignment rows and the same audit changes for the same input', function () {
    [$restGroup, $educator, $content, $priya, $sara, $tom] = cardiologyGroup();
    $mcpGroup = Group::factory()->for($restGroup->institution)->create(['name' => 'Cardiology Group B']);
    $mcpGroup->educators()->attach($educator);
    $mcpGroup->learners()->attach([$priya->id, $sara->id, $tom->id]);
    actingAsEducator($educator);

    foreach ([[$sara->id], [$sara->id, $tom->id], null] as $learnerIds) {
        $learners = $learnerIds === null ? [] : ['learner_ids' => $learnerIds];

        postJson("/api/v1/groups/{$restGroup->id}/assignments", ['content_item_id' => $content->id, 'due_at' => '2026-10-16', ...$learners])->assertSuccessful();
        callTool('assign_content', ['group_id' => $mcpGroup->id, 'content_item_id' => $content->id, 'due_date' => '2026-10-16', ...$learners])
            ->assertJsonPath('result.isError', false);
    }

    $restAssignment = Assignment::query()->where('group_id', $restGroup->id)->sole();
    $mcpAssignment = Assignment::query()->where('group_id', $mcpGroup->id)->sole();

    patchJson("/api/v1/assignments/{$restAssignment->id}", ['due_at' => '2026-10-23T18:00:00+02:00'])->assertOk();
    callTool('change_due_date', ['assignment_id' => $mcpAssignment->id, 'due_date' => '2026-10-23T18:00:00+02:00'])
        ->assertJsonPath('result.isError', false);

    $row = fn (Group $group): array => collect((array) DB::table('assignments')->where('group_id', $group->id)->sole())
        ->except(['id', 'group_id'])->all();
    $targeted = fn (Assignment $assignment): array => DB::table('assignment_learners')->where('assignment_id', $assignment->id)
        ->orderBy('user_id')->pluck('user_id')->all();
    $audit = fn (Group $group): array => AuditEntry::query()->where('group_id', $group->id)->orderBy('id')->get()
        ->map(fn (AuditEntry $entry) => [$entry->actor_id, $entry->action, $entry->subject_type, $entry->changes])->all();

    expect($row($mcpGroup))->toBe($row($restGroup))
        ->and($targeted($mcpAssignment))->toBe($targeted($restAssignment))
        ->and($audit($mcpGroup))->toEqual($audit($restGroup))
        ->and(array_column($audit($restGroup), 1))->toBe([AuditAction::AssignmentCreated, AuditAction::AssignmentLearnersAdded, AuditAction::AssignmentLearnersAdded, AuditAction::AssignmentDueChanged])
        ->and(DB::table('audit_log')->where('group_id', $restGroup->id)->distinct()->pluck('channel')->all())->toBe(['rest'])
        ->and(DB::table('audit_log')->where('group_id', $mcpGroup->id)->distinct()->pluck('channel')->all())->toBe(['mcp']);
});
