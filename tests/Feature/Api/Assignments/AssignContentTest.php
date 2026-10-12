<?php

use App\Enums\AssignmentScope;
use App\Enums\AuditAction;
use App\Enums\AuditChannel;
use App\Models\Assignment;
use App\Models\AuditEntry;
use App\Models\ContentItem;
use App\Models\Progress;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\postJson;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-09 12:00:00');
});

test('without learner_ids it creates a group assignment for every current member', function () {
    [$group, $educator, $content, $priya, $sara, $tom] = cardiologyGroup();

    actingAsEducator($educator);

    $response = postJson("/api/v1/groups/{$group->id}/assignments", ['content_item_id' => $content->id, 'due_at' => '2026-10-16'])
        ->assertCreated();

    $assignment = Assignment::query()->sole();
    $audit = AuditEntry::query()->sole();

    $response->assertExactJson([
        'data' => [
            'result' => 'created',
            'assignment' => [
                'id' => $assignment->id,
                'group_id' => $group->id,
                'content' => ['id' => $content->id, 'title' => 'Heart Failure Question Set', 'topic' => 'Cardiology', 'type' => 'question_set', 'estimated_minutes' => 30],
                'due_at' => '2026-10-16T23:59:59+02:00',
                'scope' => 'group',
                'targeted_count' => 3,
                'learners' => null,
                'created_at' => '2026-10-09T14:00:00+02:00',
            ],
            'added' => [
                ['id' => $priya->id, 'name' => 'Priya Nair'],
                ['id' => $sara->id, 'name' => 'Sara Lindqvist'],
                ['id' => $tom->id, 'name' => 'Tom Becker'],
            ],
            'already_had_it' => [],
        ],
    ]);

    expect($assignment->scope)->toBe(AssignmentScope::Group)
        ->and($assignment->created_by)->toBe($educator->id)
        ->and($assignment->due_at->toIso8601ZuluString())->toBe('2026-10-16T21:59:59Z')
        ->and(DB::table('assignment_learners')->count())->toBe(0)
        ->and($audit->action)->toBe(AuditAction::AssignmentCreated)
        ->and($audit->channel)->toBe(AuditChannel::Rest)
        ->and($audit->actor_id)->toBe($educator->id)
        ->and($audit->group_id)->toBe($group->id)
        ->and($audit->subject_type)->toBe('assignment')
        ->and($audit->subject_id)->toBe($assignment->id)
        ->and($audit->changes)->toEqual([
            'content_item_id' => $content->id,
            'due_at' => '2026-10-16T21:59:59Z',
            'scope' => 'group',
            'learner_ids' => [],
        ]);
});

test('with learner_ids it creates a subset assignment for those learners only', function () {
    [$group, $educator, $content, $priya, $sara, $tom] = cardiologyGroup();

    actingAsEducator($educator);

    postJson("/api/v1/groups/{$group->id}/assignments", ['content_item_id' => $content->id, 'due_at' => '2026-10-16', 'learner_ids' => [$tom->id, $sara->id]])
        ->assertCreated()
        ->assertJsonPath('data.result', 'created')
        ->assertJsonPath('data.assignment.scope', 'learners')
        ->assertJsonPath('data.assignment.targeted_count', 2)
        ->assertJsonPath('data.assignment.learners', [
            ['id' => $sara->id, 'name' => 'Sara Lindqvist'],
            ['id' => $tom->id, 'name' => 'Tom Becker'],
        ])
        ->assertJsonPath('data.added', [
            ['id' => $sara->id, 'name' => 'Sara Lindqvist'],
            ['id' => $tom->id, 'name' => 'Tom Becker'],
        ])
        ->assertJsonPath('data.already_had_it', []);

    $assignment = Assignment::query()->sole();
    $targetedIds = collect([$sara->id, $tom->id])->sort()->values()->all();

    expect($assignment->scope)->toBe(AssignmentScope::Learners)
        ->and(DB::table('assignment_learners')->where('assignment_id', $assignment->id)->orderBy('user_id')->pluck('user_id')->all())->toBe($targetedIds)
        ->and(AuditEntry::query()->sole()->changes)->toEqual([
            'content_item_id' => $content->id,
            'due_at' => '2026-10-16T21:59:59Z',
            'scope' => 'learners',
            'learner_ids' => $targetedIds,
        ]);
});

test('the same request twice answers 200 unchanged with already_had_it, one row and one audit row', function (bool $withLearnerIds) {
    [$group, $educator, $content, $priya, $sara] = cardiologyGroup();
    $body = ['content_item_id' => $content->id, 'due_at' => '2026-10-16', ...($withLearnerIds ? ['learner_ids' => [$sara->id, $priya->id]] : [])];

    actingAsEducator($educator);
    postJson("/api/v1/groups/{$group->id}/assignments", $body)->assertCreated();
    $first = Assignment::query()->sole();

    $response = postJson("/api/v1/groups/{$group->id}/assignments", $body)
        ->assertOk()
        ->assertJsonPath('data.result', 'unchanged')
        ->assertJsonPath('data.assignment.id', $first->id)
        ->assertJsonPath('data.added', []);

    expect($response->json('data.already_had_it.*.name'))
        ->toBe($withLearnerIds ? ['Priya Nair', 'Sara Lindqvist'] : ['Priya Nair', 'Sara Lindqvist', 'Tom Becker'])
        ->and(Assignment::query()->count())->toBe(1)
        ->and(AuditEntry::query()->count())->toBe(1);
})->with([
    'whole group' => false,
    'with learner_ids' => true,
]);

test('a bare date and the same instant with an offset are the same due date', function () {
    [$group, $educator, $content] = cardiologyGroup();

    actingAsEducator($educator);
    postJson("/api/v1/groups/{$group->id}/assignments", ['content_item_id' => $content->id, 'due_at' => '2026-10-16'])->assertCreated();

    postJson("/api/v1/groups/{$group->id}/assignments", ['content_item_id' => $content->id, 'due_at' => '2026-10-16T23:59:59+02:00'])
        ->assertOk()
        ->assertJsonPath('data.result', 'unchanged');
});

test('the same content with a different due date answers 409 with the existing assignment and changes nothing', function () {
    [$group, $educator, $content, $priya] = cardiologyGroup();
    $existing = Assignment::factory()->for($group)->for($content, 'contentItem')
        ->create(['due_at' => '2026-10-16 21:59:59', 'created_by' => $educator->id, 'created_at' => '2026-10-01 07:00:00']);
    Progress::factory()->for($existing)->for($priya, 'user')->create();
    $assignmentBefore = Assignment::query()->sole()->toArray();
    $progressBefore = Progress::query()->sole()->toArray();

    actingAsEducator($educator);

    postJson("/api/v1/groups/{$group->id}/assignments", ['content_item_id' => $content->id, 'due_at' => '2026-10-23'])
        ->assertConflict()
        ->assertExactJson([
            'error' => [
                'code' => 'already_assigned',
                'message' => "Heart Failure Question Set is already assigned to Cardiology Group A, due 2026-10-16T23:59:59+02:00. Change the due date with PATCH /api/v1/assignments/{$existing->id}.",
                'details' => [
                    'assignment' => [
                        'id' => $existing->id,
                        'group_id' => $group->id,
                        'content' => ['id' => $content->id, 'title' => 'Heart Failure Question Set', 'topic' => 'Cardiology', 'type' => 'question_set', 'estimated_minutes' => 30],
                        'due_at' => '2026-10-16T23:59:59+02:00',
                        'scope' => 'group',
                        'targeted_count' => 3,
                        'learners' => null,
                        'created_at' => '2026-10-01T09:00:00+02:00',
                    ],
                ],
            ],
        ]);

    expect(Assignment::query()->sole()->toArray())->toBe($assignmentBefore)
        ->and(Progress::query()->sole()->toArray())->toBe($progressBefore)
        ->and(AuditEntry::query()->count())->toBe(0);
});

test('a subset assignment called again with one new learner adds only that learner', function () {
    [$group, $educator, $content, $priya, $sara, $tom] = cardiologyGroup();

    actingAsEducator($educator);
    postJson("/api/v1/groups/{$group->id}/assignments", ['content_item_id' => $content->id, 'due_at' => '2026-10-16', 'learner_ids' => [$sara->id, $tom->id]])->assertCreated();
    $assignment = Assignment::query()->sole();

    postJson("/api/v1/groups/{$group->id}/assignments", ['content_item_id' => $content->id, 'due_at' => '2026-10-16', 'learner_ids' => [$sara->id, $tom->id, $priya->id]])
        ->assertOk()
        ->assertJsonPath('data.result', 'learners_added')
        ->assertJsonPath('data.assignment.id', $assignment->id)
        ->assertJsonPath('data.assignment.scope', 'learners')
        ->assertJsonPath('data.assignment.targeted_count', 3)
        ->assertJsonPath('data.added', [['id' => $priya->id, 'name' => 'Priya Nair']])
        ->assertJsonPath('data.already_had_it', [
            ['id' => $sara->id, 'name' => 'Sara Lindqvist'],
            ['id' => $tom->id, 'name' => 'Tom Becker'],
        ]);

    $audit = AuditEntry::query()->latest('id')->first();

    expect(Assignment::query()->count())->toBe(1)
        ->and(DB::table('assignment_learners')->where('assignment_id', $assignment->id)->count())->toBe(3)
        ->and(AuditEntry::query()->count())->toBe(2)
        ->and($audit?->action)->toBe(AuditAction::AssignmentLearnersAdded)
        ->and($audit?->subject_id)->toBe($assignment->id)
        ->and($audit?->changes)->toBe(['learner_ids' => [$priya->id]]);
});

test('a subset assignment called again without learner_ids widens to the group and adds the members not yet targeted', function () {
    [$group, $educator, $content, $priya, $sara, $tom] = cardiologyGroup();

    actingAsEducator($educator);
    postJson("/api/v1/groups/{$group->id}/assignments", ['content_item_id' => $content->id, 'due_at' => '2026-10-16', 'learner_ids' => [$sara->id]])->assertCreated();
    $assignment = Assignment::query()->sole();

    postJson("/api/v1/groups/{$group->id}/assignments", ['content_item_id' => $content->id, 'due_at' => '2026-10-16'])
        ->assertOk()
        ->assertJsonPath('data.result', 'learners_added')
        ->assertJsonPath('data.assignment.scope', 'group')
        ->assertJsonPath('data.assignment.targeted_count', 3)
        ->assertJsonPath('data.assignment.learners', null)
        ->assertJsonPath('data.added', [
            ['id' => $priya->id, 'name' => 'Priya Nair'],
            ['id' => $tom->id, 'name' => 'Tom Becker'],
        ])
        ->assertJsonPath('data.already_had_it', [['id' => $sara->id, 'name' => 'Sara Lindqvist']]);

    $audit = AuditEntry::query()->latest('id')->first();

    expect($assignment->fresh()?->scope)->toBe(AssignmentScope::Group)
        ->and(DB::table('assignment_learners')->where('assignment_id', $assignment->id)->pluck('user_id')->all())->toBe([$sara->id])
        ->and($audit?->action)->toBe(AuditAction::AssignmentLearnersAdded)
        ->and($audit?->changes)->toEqual([
            'learner_ids' => collect([$priya->id, $tom->id])->sort()->values()->all(),
            'scope' => ['from' => 'learners', 'to' => 'group'],
        ]);
});

test('widening a subset that already covers every member still widens and writes the audit row', function () {
    [$group, $educator, $content, $priya, $sara, $tom] = cardiologyGroup();

    actingAsEducator($educator);
    postJson("/api/v1/groups/{$group->id}/assignments", ['content_item_id' => $content->id, 'due_at' => '2026-10-16', 'learner_ids' => [$priya->id, $sara->id, $tom->id]])->assertCreated();
    $assignment = Assignment::query()->sole();

    postJson("/api/v1/groups/{$group->id}/assignments", ['content_item_id' => $content->id, 'due_at' => '2026-10-16'])
        ->assertOk()
        ->assertJsonPath('data.result', 'learners_added')
        ->assertJsonPath('data.assignment.scope', 'group')
        ->assertJsonPath('data.added', [])
        ->assertJsonCount(3, 'data.already_had_it');

    expect($assignment->fresh()?->scope)->toBe(AssignmentScope::Group)
        ->and(AuditEntry::query()->count())->toBe(2)
        ->and(AuditEntry::query()->latest('id')->first()?->changes)->toEqual([
            'learner_ids' => [],
            'scope' => ['from' => 'learners', 'to' => 'group'],
        ]);

    postJson("/api/v1/groups/{$group->id}/assignments", ['content_item_id' => $content->id, 'due_at' => '2026-10-16'])
        ->assertOk()
        ->assertJsonPath('data.result', 'unchanged');

    expect(AuditEntry::query()->count())->toBe(2);
});

test('a learner who is not a member, a past due date or a due date that is not a date answers 422 and writes nothing', function (Closure $makeBody, string $field, string $message) {
    [$group, $educator, $content, $priya] = cardiologyGroup();
    $outsider = User::factory()->learner()->create(['institution_id' => $group->institution_id]);

    actingAsEducator($educator);

    postJson("/api/v1/groups/{$group->id}/assignments", $makeBody($content, $priya, $outsider))
        ->assertUnprocessable()
        ->assertExactJson([
            'error' => [
                'code' => 'validation_failed',
                'message' => 'The given data was invalid.',
                'details' => [$field => [str_replace(':outsider', (string) $outsider->id, $message)]],
            ],
        ]);

    expect(Assignment::query()->count())->toBe(0)
        ->and(DB::table('assignment_learners')->count())->toBe(0)
        ->and(AuditEntry::query()->count())->toBe(0);
})->with([
    'a learner who is not a member' => [
        fn (ContentItem $content, User $priya, User $outsider) => ['content_item_id' => $content->id, 'due_at' => '2026-10-16', 'learner_ids' => [$priya->id, $outsider->id]],
        'learner_ids.1',
        'Learner :outsider is not a member of this group.',
    ],
    'a due date in the past' => [
        fn (ContentItem $content, User $priya, User $outsider) => ['content_item_id' => $content->id, 'due_at' => '2026-10-08'],
        'due_at',
        'The due date must be in the future.',
    ],
    'a due date that is not a date' => [
        fn (ContentItem $content, User $priya, User $outsider) => ['content_item_id' => $content->id, 'due_at' => 'next friday'],
        'due_at',
        'The due date must be a date (YYYY-MM-DD) or an ISO 8601 date-time.',
    ],
    'a due date that is not a string' => [
        fn (ContentItem $content, User $priya, User $outsider) => ['content_item_id' => $content->id, 'due_at' => 20261016],
        'due_at',
        'The due date must be a date (YYYY-MM-DD) or an ISO 8601 date-time.',
    ],
]);

test('a body of the wrong shape answers 422 and writes nothing', function (Closure $makeBody, string $field) {
    [$group, $educator, $content, $priya] = cardiologyGroup();

    actingAsEducator($educator);

    postJson("/api/v1/groups/{$group->id}/assignments", $makeBody($content, $priya))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonValidationErrors([$field], 'error.details');

    expect(Assignment::query()->count())->toBe(0)
        ->and(AuditEntry::query()->count())->toBe(0);
})->with([
    'no content_item_id' => [fn (ContentItem $content, User $priya) => ['due_at' => '2026-10-16'], 'content_item_id'],
    'a content_item_id that is not an integer' => [fn (ContentItem $content, User $priya) => ['content_item_id' => (string) $content->id, 'due_at' => '2026-10-16'], 'content_item_id'],
    'no due_at' => [fn (ContentItem $content, User $priya) => ['content_item_id' => $content->id], 'due_at'],
    'empty learner_ids' => [fn (ContentItem $content, User $priya) => ['content_item_id' => $content->id, 'due_at' => '2026-10-16', 'learner_ids' => []], 'learner_ids'],
    'a repeated learner id' => [fn (ContentItem $content, User $priya) => ['content_item_id' => $content->id, 'due_at' => '2026-10-16', 'learner_ids' => [$priya->id, $priya->id]], 'learner_ids.0'],
    'more than 60 learner ids' => [fn (ContentItem $content, User $priya) => ['content_item_id' => $content->id, 'due_at' => '2026-10-16', 'learner_ids' => range(1, 61)], 'learner_ids'],
]);

test('an unknown content item answers 404 Content item not found. and writes nothing', function () {
    [$group, $educator, $content] = cardiologyGroup();

    actingAsEducator($educator);

    postJson("/api/v1/groups/{$group->id}/assignments", ['content_item_id' => $content->id + 1000, 'due_at' => '2026-10-16'])
        ->assertNotFound()
        ->assertExactJson(['error' => ['code' => 'not_found', 'message' => 'Content item not found.']]);

    expect(Assignment::query()->count())->toBe(0)
        ->and(AuditEntry::query()->count())->toBe(0);
});

test('a request racing another one that inserts the same assignment gets the existing one', function (string $otherDueAt, int $status, string $code) {
    [$group, $educator, $content] = cardiologyGroup();
    $otherEducator = educatorOf($group);
    $step = 'before insert';
    $insertOther = fn () => DB::table('assignments')->insert([
        'group_id' => $group->id,
        'content_item_id' => $content->id,
        'due_at' => $otherDueAt,
        'scope' => 'group',
        'created_by' => $otherEducator->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // The other request's row collides with our insert and, like a committed row, outlives our rollback.
    DB::beforeExecuting(function (string $query) use (&$step, $insertOther) {
        if ($step === 'before insert' && str_starts_with($query, 'insert into `assignments`')) {
            $step = 'before rollback';
            $insertOther();
        }
    });
    Event::listen(TransactionRolledBack::class, function () use (&$step, $insertOther) {
        if ($step === 'before rollback') {
            $step = 'done';
            $insertOther();
        }
    });

    actingAsEducator($educator);

    $response = postJson("/api/v1/groups/{$group->id}/assignments", ['content_item_id' => $content->id, 'due_at' => '2026-10-16']);

    expect($step)->toBe('done')
        ->and($response->status())->toBe($status)
        ->and($response->json('data.result') ?? $response->json('error.code'))->toBe($code)
        ->and(Assignment::query()->sole()->created_by)->toBe($otherEducator->id)
        ->and(AuditEntry::query()->count())->toBe(0);
})->with([
    'same due date' => ['2026-10-16 21:59:59', 200, 'unchanged'],
    'another due date' => ['2026-10-23 21:59:59', 409, 'already_assigned'],
]);
