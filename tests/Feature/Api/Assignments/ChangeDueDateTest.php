<?php

use App\Enums\AuditAction;
use App\Enums\AuditChannel;
use App\Models\Assignment;
use App\Models\AuditEntry;
use App\Models\Group;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\patchJson;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-09 12:00:00');
});

test('a new date answers changed true with the previous date and writes one audit row', function () {
    [$group, $educator, $content, $priya, $sara] = cardiologyGroup();
    $assignment = Assignment::factory()->for($group)->for($content, 'contentItem')->forLearners([$sara, $priya])
        ->create(['due_at' => '2026-10-16 21:59:59', 'created_by' => $educator->id, 'created_at' => '2026-10-01 07:00:00']);

    actingAsEducator($educator);

    patchJson("/api/v1/assignments/{$assignment->id}", ['due_at' => '2026-10-23'])
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'changed' => true,
                'due_at' => '2026-10-23T23:59:59+02:00',
                'previous_due_at' => '2026-10-16T23:59:59+02:00',
                'assignment' => [
                    'id' => $assignment->id,
                    'group_id' => $group->id,
                    'content' => ['id' => $content->id, 'title' => 'Heart Failure Question Set', 'topic' => 'Cardiology', 'type' => 'question_set', 'estimated_minutes' => 30],
                    'due_at' => '2026-10-23T23:59:59+02:00',
                    'scope' => 'learners',
                    'targeted_count' => 2,
                    'learners' => [
                        ['id' => $priya->id, 'name' => 'Priya Nair'],
                        ['id' => $sara->id, 'name' => 'Sara Lindqvist'],
                    ],
                    'created_at' => '2026-10-01T09:00:00+02:00',
                ],
            ],
        ]);

    $audit = AuditEntry::query()->sole();

    expect($assignment->fresh()?->due_at->toIso8601ZuluString())->toBe('2026-10-23T21:59:59Z')
        ->and($audit->action)->toBe(AuditAction::AssignmentDueChanged)
        ->and($audit->channel)->toBe(AuditChannel::Rest)
        ->and($audit->actor_id)->toBe($educator->id)
        ->and($audit->group_id)->toBe($group->id)
        ->and($audit->subject_type)->toBe('assignment')
        ->and($audit->subject_id)->toBe($assignment->id)
        ->and($audit->changes)->toEqual(['due_at' => ['from' => '2026-10-16T21:59:59Z', 'to' => '2026-10-23T21:59:59Z']]);
});

test('the same instant answers changed false with previous_due_at equal to due_at and writes nothing', function (string $dueAt) {
    [$group, $educator] = cardiologyGroup();
    $assignment = Assignment::factory()->for($group)->create(['due_at' => '2026-10-16 21:59:59']);
    $before = $assignment->fresh()?->toArray();

    actingAsEducator($educator);

    patchJson("/api/v1/assignments/{$assignment->id}", ['due_at' => $dueAt])
        ->assertOk()
        ->assertJsonPath('data.changed', false)
        ->assertJsonPath('data.due_at', '2026-10-16T23:59:59+02:00')
        ->assertJsonPath('data.previous_due_at', '2026-10-16T23:59:59+02:00')
        ->assertJsonPath('data.assignment.due_at', '2026-10-16T23:59:59+02:00');

    expect($assignment->fresh()?->toArray())->toBe($before)
        ->and(AuditEntry::query()->count())->toBe(0);
})->with([
    'as a bare date' => '2026-10-16',
    'with an offset' => '2026-10-16T21:59:59Z',
]);

test('a due date in the past or not a date answers 422 and changes nothing', function (mixed $dueAt, string $message) {
    [$group, $educator] = cardiologyGroup();
    $assignment = Assignment::factory()->for($group)->create(['due_at' => '2026-10-16 21:59:59']);

    actingAsEducator($educator);

    patchJson("/api/v1/assignments/{$assignment->id}", ['due_at' => $dueAt])
        ->assertUnprocessable()
        ->assertExactJson([
            'error' => [
                'code' => 'validation_failed',
                'message' => 'The given data was invalid.',
                'details' => ['due_at' => [$message]],
            ],
        ]);

    expect($assignment->fresh()?->due_at->toIso8601ZuluString())->toBe('2026-10-16T21:59:59Z')
        ->and(AuditEntry::query()->count())->toBe(0);
})->with([
    'yesterday' => ['2026-10-08', 'The due date must be in the future.'],
    'an hour ago' => ['2026-10-09T11:00:00Z', 'The due date must be in the future.'],
    'words' => ['next friday', 'The due date must be a date (YYYY-MM-DD) or an ISO 8601 date-time.'],
    'a number' => [20261023, 'The due date must be a date (YYYY-MM-DD) or an ISO 8601 date-time.'],
]);

test('a missing due_at answers 422', function () {
    [$group, $educator] = cardiologyGroup();
    $assignment = Assignment::factory()->for($group)->create();

    actingAsEducator($educator);

    patchJson("/api/v1/assignments/{$assignment->id}", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['due_at'], 'error.details');
});

test('another educator\'s assignment, a removed assignment and a missing id answer the same 404 and change nothing', function () {
    [$group, $educator] = cardiologyGroup();
    $removed = Assignment::factory()->for($group)->removed()->create(['due_at' => '2026-10-16 21:59:59']);
    $foreignGroup = Group::factory()->create();
    educatorOf($foreignGroup);
    $foreign = Assignment::factory()->for($foreignGroup)->create(['due_at' => '2026-10-16 21:59:59']);
    $missingId = $foreign->id + 1000;

    actingAsEducator($educator);

    $responses = collect([$foreign->id, $removed->id, $missingId])
        ->map(fn (int $id) => patchJson("/api/v1/assignments/{$id}", ['due_at' => '2026-10-23']));

    $responses[0]->assertNotFound()->assertExactJson(['error' => ['code' => 'not_found', 'message' => 'Assignment not found.']]);

    expect($responses->map(fn (TestResponse $response) => [$response->status(), $response->getContent()])->unique())->toHaveCount(1)
        ->and($foreign->fresh()?->due_at->toIso8601ZuluString())->toBe('2026-10-16T21:59:59Z')
        ->and($removed->fresh()?->due_at->toIso8601ZuluString())->toBe('2026-10-16T21:59:59Z')
        ->and(AuditEntry::query()->count())->toBe(0);
});

test('another educator\'s assignment and a missing id answer 404 before the body is checked', function () {
    [, $educator] = cardiologyGroup();
    $foreignGroup = Group::factory()->create();
    educatorOf($foreignGroup);
    $foreign = Assignment::factory()->for($foreignGroup)->create();

    actingAsEducator($educator);

    foreach ([$foreign->id, $foreign->id + 1000] as $id) {
        patchJson("/api/v1/assignments/{$id}", [])
            ->assertNotFound()
            ->assertExactJson(['error' => ['code' => 'not_found', 'message' => 'Assignment not found.']]);
    }
});

test('a non-numeric id answers 404 Not found.', function () {
    actingAsEducator();

    patchJson('/api/v1/assignments/abc', ['due_at' => '2026-10-23'])
        ->assertNotFound()
        ->assertExactJson(['error' => ['code' => 'not_found', 'message' => 'Not found.']]);
});
