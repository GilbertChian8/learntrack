<?php

use App\Enums\AuditAction;
use App\Enums\AuditChannel;
use App\Models\Assignment;
use App\Models\AuditEntry;
use App\Models\Group;
use App\Models\Progress;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-09 12:10:02');
});

test('removes the assignment, keeps its progress, writes one audit row, and a repeat writes none', function () {
    [$group, $educator, $content, $priya, $sara] = cardiologyGroup();
    $assignment = Assignment::factory()->for($group)->for($content, 'contentItem')->create();
    $progress = Progress::factory()->for($assignment)->for($priya, 'user')->completed()->create();
    $started = Progress::factory()->for($assignment)->for($sara, 'user')->create();

    actingAsEducator($educator);

    deleteJson("/api/v1/assignments/{$assignment->id}")->assertNoContent();

    $removed = $assignment->fresh();
    $audit = AuditEntry::query()->sole();

    expect($removed?->removed_at?->toIso8601ZuluString())->toBe('2026-10-09T12:10:02Z')
        ->and($removed?->removed_key)->toBe($assignment->id)
        ->and(Progress::query()->whereKey([$progress->id, $started->id])->count())->toBe(2)
        ->and($audit->action)->toBe(AuditAction::AssignmentRemoved)
        ->and($audit->channel)->toBe(AuditChannel::Rest)
        ->and($audit->actor_id)->toBe($educator->id)
        ->and($audit->group_id)->toBe($group->id)
        ->and($audit->subject_type)->toBe('assignment')
        ->and($audit->subject_id)->toBe($assignment->id)
        ->and($audit->changes)->toBe(['removed_at' => '2026-10-09T12:10:02Z']);

    CarbonImmutable::setTestNow('2026-10-09 13:00:00');

    deleteJson("/api/v1/assignments/{$assignment->id}")->assertNoContent();

    expect(AuditEntry::query()->count())->toBe(1)
        ->and($assignment->fresh()?->removed_at?->toIso8601ZuluString())->toBe('2026-10-09T12:10:02Z');
});

test('a removed assignment leaves the list, and the same content can be assigned again as a new row', function () {
    [$group, $educator, $content] = cardiologyGroup();

    actingAsEducator($educator);

    $first = postJson("/api/v1/groups/{$group->id}/assignments", ['content_item_id' => $content->id, 'due_at' => '2026-10-16'])
        ->assertCreated()
        ->json('data.assignment.id');

    deleteJson("/api/v1/assignments/{$first}")->assertNoContent();

    getJson("/api/v1/groups/{$group->id}/assignments")->assertOk()->assertJsonPath('data', []);

    $second = postJson("/api/v1/groups/{$group->id}/assignments", ['content_item_id' => $content->id, 'due_at' => '2026-10-16'])
        ->assertCreated()
        ->assertJsonPath('data.result', 'created')
        ->json('data.assignment.id');

    expect($second)->not->toBe($first)
        ->and(Assignment::query()->count())->toBe(2)
        ->and(getJson("/api/v1/groups/{$group->id}/assignments")->json('data.*.id'))->toBe([$second]);
});

test('another educator\'s assignment and a missing id answer the same 404 and change nothing', function () {
    [, $educator] = cardiologyGroup();
    $foreignGroup = Group::factory()->create();
    educatorOf($foreignGroup);
    $foreign = Assignment::factory()->for($foreignGroup)->create();
    $foreignRemoved = Assignment::factory()->for($foreignGroup)->removed()->create();

    actingAsEducator($educator);

    $responses = collect([$foreign->id, $foreignRemoved->id, $foreignRemoved->id + 1000])
        ->map(fn (int $id) => deleteJson("/api/v1/assignments/{$id}"));

    $responses[0]->assertNotFound()->assertExactJson(['error' => ['code' => 'not_found', 'message' => 'Assignment not found.']]);

    expect($responses->map(fn (TestResponse $response) => [$response->status(), $response->getContent()])->unique())->toHaveCount(1)
        ->and($foreign->fresh()?->removed_at)->toBeNull()
        ->and(AuditEntry::query()->count())->toBe(0);
});

test('a non-numeric id answers 404 Not found.', function () {
    actingAsEducator();

    deleteJson('/api/v1/assignments/abc')
        ->assertNotFound()
        ->assertExactJson(['error' => ['code' => 'not_found', 'message' => 'Not found.']]);
});
