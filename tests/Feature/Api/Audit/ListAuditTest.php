<?php

use App\Enums\AuditAction;
use App\Models\AuditEntry;
use App\Models\Group;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutMiddleware;

test('lists every action newest first with the actor\'s name and the contract\'s changes', function () {
    [$groupA, $educator, $content, $priya, $sara, $tom] = cardiologyGroup();
    $at = fn (string $time) => CarbonImmutable::setTestNow("2026-10-09 {$time}");

    actingAsEducator($educator);

    $at('07:00:00');
    $groupId = postJson('/api/v1/groups', ['name' => 'Cardiology Group B'])->assertCreated()->json('data.id');
    $at('07:01:00');
    postJson("/api/v1/groups/{$groupId}/learners", ['user_ids' => [$tom->id, $priya->id, $sara->id]])->assertOk();
    $at('07:02:00');
    $assignmentId = postJson("/api/v1/groups/{$groupId}/assignments", ['content_item_id' => $content->id, 'due_at' => '2026-10-16', 'learner_ids' => [$sara->id]])
        ->assertCreated()
        ->json('data.assignment.id');
    $at('07:03:00');
    postJson("/api/v1/groups/{$groupId}/assignments", ['content_item_id' => $content->id, 'due_at' => '2026-10-16', 'learner_ids' => [$sara->id, $priya->id]])->assertOk();
    $at('07:04:00');
    postJson("/api/v1/groups/{$groupId}/assignments", ['content_item_id' => $content->id, 'due_at' => '2026-10-16'])->assertOk();
    $at('07:05:00');
    patchJson("/api/v1/assignments/{$assignmentId}", ['due_at' => '2026-10-23'])->assertOk();
    $at('07:06:00');
    deleteJson("/api/v1/assignments/{$assignmentId}")->assertNoContent();
    $at('07:07:00');
    deleteJson("/api/v1/groups/{$groupId}/learners/{$tom->id}")->assertNoContent();

    $ids = AuditEntry::query()->where('group_id', $groupId)->orderByDesc('id')->pluck('id');
    $entry = fn (int $index, AuditAction $action, string $subjectType, int $subjectId, array $changes, string $createdAt) => [
        'id' => $ids[$index],
        'actor' => ['id' => $educator->id, 'name' => 'Anna Keller'],
        'channel' => 'rest',
        'action' => $action->value,
        'subject' => ['type' => $subjectType, 'id' => $subjectId],
        'changes' => $changes,
        'created_at' => $createdAt,
    ];

    getJson("/api/v1/groups/{$groupId}/audit")
        ->assertOk()
        ->assertExactJson([
            'data' => [
                $entry(0, AuditAction::GroupLearnerRemoved, 'group', $groupId, ['user_id' => $tom->id], '2026-10-09T09:07:00+02:00'),
                $entry(1, AuditAction::AssignmentRemoved, 'assignment', $assignmentId, ['removed_at' => '2026-10-09T07:06:00Z'], '2026-10-09T09:06:00+02:00'),
                $entry(2, AuditAction::AssignmentDueChanged, 'assignment', $assignmentId, ['due_at' => ['from' => '2026-10-16T21:59:59Z', 'to' => '2026-10-23T21:59:59Z']], '2026-10-09T09:05:00+02:00'),
                $entry(3, AuditAction::AssignmentLearnersAdded, 'assignment', $assignmentId, ['learner_ids' => [$tom->id], 'scope' => ['from' => 'learners', 'to' => 'group']], '2026-10-09T09:04:00+02:00'),
                $entry(4, AuditAction::AssignmentLearnersAdded, 'assignment', $assignmentId, ['learner_ids' => [$priya->id]], '2026-10-09T09:03:00+02:00'),
                $entry(5, AuditAction::AssignmentCreated, 'assignment', $assignmentId, ['content_item_id' => $content->id, 'due_at' => '2026-10-16T21:59:59Z', 'scope' => 'learners', 'learner_ids' => [$sara->id]], '2026-10-09T09:02:00+02:00'),
                $entry(6, AuditAction::GroupLearnerAdded, 'group', $groupId, ['user_id' => $tom->id], '2026-10-09T09:01:00+02:00'),
                $entry(7, AuditAction::GroupLearnerAdded, 'group', $groupId, ['user_id' => $sara->id], '2026-10-09T09:01:00+02:00'),
                $entry(8, AuditAction::GroupLearnerAdded, 'group', $groupId, ['user_id' => $priya->id], '2026-10-09T09:01:00+02:00'),
                $entry(9, AuditAction::GroupCreated, 'group', $groupId, ['name' => 'Cardiology Group B'], '2026-10-09T09:00:00+02:00'),
            ],
            'meta' => ['next_cursor' => null],
        ]);

    expect(getJson("/api/v1/groups/{$groupA->id}/audit")->assertOk()->json('data'))->toBe([]);
});

test('pages newest first by created_at to the microsecond, then by id, and lists only this group', function () {
    [$group, $educator] = cardiologyGroup();
    $write = fn (string $createdAt, ?Group $other = null) => AuditEntry::factory()->create([
        'actor_id' => $educator->id,
        'group_id' => ($other ?? $group)->id,
        'created_at' => $createdAt,
    ])->id;

    $older = $write('2026-10-09 11:59:59.900000');
    $earliestInSecond = $write('2026-10-09 12:00:00.300000');
    $latest = $write('2026-10-09 12:00:00.700000');
    $tiedFirst = $write('2026-10-09 12:00:00.500000');
    $tiedSecond = $write('2026-10-09 12:00:00.500000');
    $write('2026-10-09 12:00:01.000000', Group::factory()->create());

    actingAsEducator($educator);

    $ids = [];
    $cursor = null;

    do {
        $page = getJson("/api/v1/groups/{$group->id}/audit?".http_build_query(['limit' => 2, 'cursor' => $cursor]))->assertOk();
        $ids = [...$ids, ...$page->json('data.*.id')];
        $cursor = $page->json('meta.next_cursor');
    } while ($cursor !== null);

    expect($ids)->toBe([$latest, $tiedSecond, $tiedFirst, $earliestInSecond, $older]);

    getJson("/api/v1/groups/{$group->id}/audit?limit=0")->assertUnprocessable();
    getJson("/api/v1/groups/{$group->id}/audit?cursor=garbage")
        ->assertUnprocessable()
        ->assertJsonPath('error.details.cursor', ['The cursor is invalid.']);
});

test('actor names are read in one query, whatever the number of actors', function () {
    withoutMiddleware(ThrottleRequests::class);
    [$group] = cardiologyGroup();
    $viewer = educatorOf($group);

    $queryCount = function (int $actors) use ($group, $viewer): int {
        AuditEntry::query()->delete();
        User::factory()->educator()->count($actors)->create(['institution_id' => $group->institution_id])
            ->each(fn (User $actor) => AuditEntry::factory()->create(['actor_id' => $actor->id, 'group_id' => $group->id]));

        actingAsEducator($viewer);
        DB::enableQueryLog();
        DB::flushQueryLog();

        getJson("/api/v1/groups/{$group->id}/audit")->assertOk()->assertJsonCount($actors, 'data');

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    expect($queryCount(3))->toBe(3)
        ->and($queryCount(30))->toBe(3);
});
