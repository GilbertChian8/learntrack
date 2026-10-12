<?php

use App\Enums\ContentType;
use App\Enums\ProgressStatus;
use App\Models\Assignment;
use App\Models\AuditEntry;
use App\Models\ContentItem;
use App\Models\Group;
use App\Models\Progress;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\putJson;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-09 12:00:00');
});

test('in_progress with no row starts it, and the same call again changes nothing', function () {
    [$group, , $content, $priya] = cardiologyGroup();
    $assignment = Assignment::factory()->for($group)->for($content, 'contentItem')->create(['due_at' => '2026-10-16 21:59:59']);

    actingAsLearner($priya);

    $expected = [
        'assignment_id' => $assignment->id,
        'status' => 'in_progress',
        'score' => null,
        'started_at' => '2026-10-09T14:00:00+02:00',
        'completed_at' => null,
        'due_at' => '2026-10-16T23:59:59+02:00',
    ];

    putJson("/api/v1/me/assignments/{$assignment->id}/progress", ['status' => 'in_progress'])
        ->assertOk()
        ->assertExactJson(['data' => [...$expected, 'changed' => true]]);

    CarbonImmutable::setTestNow('2026-10-09 13:00:00');

    putJson("/api/v1/me/assignments/{$assignment->id}/progress", ['status' => 'in_progress'])
        ->assertOk()
        ->assertExactJson(['data' => [...$expected, 'changed' => false]]);

    $row = Progress::query()->sole();

    expect($row->status)->toBe(ProgressStatus::InProgress)
        ->and($row->user_id)->toBe($priya->id)
        ->and($row->started_at->toIso8601ZuluString())->toBe('2026-10-09T12:00:00Z')
        ->and($row->completed_at)->toBeNull()
        ->and(AuditEntry::query()->count())->toBe(0);
});

test('completed with a score finishes a started question set; the same score changes nothing, another score or in_progress answers 409', function () {
    [$group, , $content, $priya] = cardiologyGroup();
    $assignment = Assignment::factory()->for($group)->for($content, 'contentItem')->create(['due_at' => '2026-10-16 21:59:59']);
    Progress::factory()->for($assignment)->for($priya, 'user')->create(['started_at' => '2026-10-08 06:12:00']);
    $url = "/api/v1/me/assignments/{$assignment->id}/progress";

    actingAsLearner($priya);

    $expected = [
        'assignment_id' => $assignment->id,
        'status' => 'completed',
        'score' => 72,
        'started_at' => '2026-10-08T08:12:00+02:00',
        'completed_at' => '2026-10-09T14:00:00+02:00',
        'due_at' => '2026-10-16T23:59:59+02:00',
    ];
    $conflict = [
        'error' => [
            'code' => 'already_completed',
            'message' => 'This assignment is already completed with a score of 72.',
            'details' => ['status' => 'completed', 'score' => 72, 'completed_at' => '2026-10-09T14:00:00+02:00'],
        ],
    ];

    putJson($url, ['status' => 'completed', 'score' => 72])->assertOk()->assertExactJson(['data' => [...$expected, 'changed' => true]]);

    CarbonImmutable::setTestNow('2026-10-10 08:00:00');

    putJson($url, ['status' => 'completed', 'score' => 72])->assertOk()->assertExactJson(['data' => [...$expected, 'changed' => false]]);
    putJson($url, ['status' => 'completed', 'score' => 90])->assertConflict()->assertExactJson($conflict);
    putJson($url, ['status' => 'in_progress'])->assertConflict()->assertExactJson($conflict);

    $row = Progress::query()->sole();

    expect($row->status)->toBe(ProgressStatus::Completed)
        ->and($row->score)->toBe(72)
        ->and($row->completed_at?->toIso8601ZuluString())->toBe('2026-10-09T12:00:00Z');
});

test('completed with no row creates one row with started_at and completed_at now', function () {
    [$group, , $content, $priya] = cardiologyGroup();
    $assignment = Assignment::factory()->for($group)->for($content, 'contentItem')->create(['due_at' => '2026-10-16 21:59:59']);

    actingAsLearner($priya);

    putJson("/api/v1/me/assignments/{$assignment->id}/progress", ['status' => 'completed', 'score' => 0])
        ->assertOk()
        ->assertExactJson(['data' => [
            'assignment_id' => $assignment->id,
            'status' => 'completed',
            'score' => 0,
            'started_at' => '2026-10-09T14:00:00+02:00',
            'completed_at' => '2026-10-09T14:00:00+02:00',
            'due_at' => '2026-10-16T23:59:59+02:00',
            'changed' => true,
        ]]);

    $row = Progress::query()->sole();

    expect($row->started_at->toIso8601ZuluString())->toBe('2026-10-09T12:00:00Z')
        ->and($row->completed_at?->toIso8601ZuluString())->toBe('2026-10-09T12:00:00Z');
});

test('an article completes without a score; going back answers 409 with a null score', function () {
    [$group, , , $priya] = cardiologyGroup();
    $article = ContentItem::factory()->create(['type' => ContentType::Article]);
    $assignment = Assignment::factory()->for($group)->for($article, 'contentItem')->create();
    $url = "/api/v1/me/assignments/{$assignment->id}/progress";

    actingAsLearner($priya);

    putJson($url, ['status' => 'completed'])->assertOk()->assertJsonPath('data.changed', true)->assertJsonPath('data.score', null);
    putJson($url, ['status' => 'completed', 'score' => null])->assertOk()->assertJsonPath('data.changed', false);
    putJson($url, ['status' => 'in_progress'])
        ->assertConflict()
        ->assertExactJson(['error' => [
            'code' => 'already_completed',
            'message' => 'This assignment is already completed.',
            'details' => ['status' => 'completed', 'score' => null, 'completed_at' => '2026-10-09T14:00:00+02:00'],
        ]]);

    expect(Progress::query()->count())->toBe(1);
});

test('a score that breaks the rules answers 422 on score and writes nothing', function (string $type, array $body, string $message) {
    [$group, , , $priya] = cardiologyGroup();
    $content = ContentItem::factory()->create(['type' => $type]);
    $assignment = Assignment::factory()->for($group)->for($content, 'contentItem')->create();

    actingAsLearner($priya);

    putJson("/api/v1/me/assignments/{$assignment->id}/progress", $body)
        ->assertUnprocessable()
        ->assertExactJson(['error' => [
            'code' => 'validation_failed',
            'message' => 'The given data was invalid.',
            'details' => ['score' => [$message]],
        ]]);

    expect(Progress::query()->count())->toBe(0);
})->with([
    'a question set completed without a score' => ['question_set', ['status' => 'completed'], 'A completed question set needs a score.'],
    'a question set completed with a null score' => ['question_set', ['status' => 'completed', 'score' => null], 'A completed question set needs a score.'],
    'an article completed with a score' => ['article', ['status' => 'completed', 'score' => 80], 'An article does not take a score.'],
    'in_progress with a score' => ['question_set', ['status' => 'in_progress', 'score' => 50], 'A score is only accepted with the status completed.'],
    'a score of 101' => ['question_set', ['status' => 'completed', 'score' => 101], 'The score field must be between 0 and 100.'],
    'a score of -1' => ['question_set', ['status' => 'completed', 'score' => -1], 'The score field must be between 0 and 100.'],
    'a score that is not an integer' => ['question_set', ['status' => 'completed', 'score' => '72'], 'The score field must be an integer.'],
]);

test('a missing or unknown status answers 422 on status', function (array $body, string $message) {
    [$group, , $content, $priya] = cardiologyGroup();
    $assignment = Assignment::factory()->for($group)->for($content, 'contentItem')->create();

    actingAsLearner($priya);

    putJson("/api/v1/me/assignments/{$assignment->id}/progress", $body)
        ->assertUnprocessable()
        ->assertJsonPath('error.details', ['status' => [$message]]);

    expect(Progress::query()->count())->toBe(0);
})->with([
    'missing' => [['score' => 72], 'The status field is required.'],
    'not_started' => [['status' => 'not_started'], 'The selected status is invalid.'],
]);

test('completing after the due date answers completed, with completed_at after due_at', function () {
    [$group, , $content, $priya] = cardiologyGroup();
    $assignment = Assignment::factory()->for($group)->for($content, 'contentItem')->create(['due_at' => '2026-10-05 21:59:59']);
    Progress::factory()->for($assignment)->for($priya, 'user')->create(['started_at' => '2026-10-04 08:00:00']);

    actingAsLearner($priya);

    $response = putJson("/api/v1/me/assignments/{$assignment->id}/progress", ['status' => 'completed', 'score' => 45])
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.changed', true)
        ->assertJsonPath('data.due_at', '2026-10-05T23:59:59+02:00')
        ->assertJsonPath('data.completed_at', '2026-10-09T14:00:00+02:00');

    expect(CarbonImmutable::parse($response->json('data.completed_at'))->isAfter(CarbonImmutable::parse($response->json('data.due_at'))))->toBeTrue();
});

test('in_progress on an overdue assignment answers status overdue', function () {
    [$group, , $content, $priya] = cardiologyGroup();
    $assignment = Assignment::factory()->for($group)->for($content, 'contentItem')->create(['due_at' => '2026-10-05 21:59:59']);

    actingAsLearner($priya);

    putJson("/api/v1/me/assignments/{$assignment->id}/progress", ['status' => 'in_progress'])
        ->assertOk()
        ->assertJsonPath('data.status', 'overdue')
        ->assertJsonPath('data.changed', true)
        ->assertJsonPath('data.started_at', '2026-10-09T14:00:00+02:00');

    expect(Progress::query()->sole()->status)->toBe(ProgressStatus::InProgress);
});

test('a removed, foreign, non-targeting or missing assignment answers the same 404 before validation and writes nothing', function (Closure $target) {
    [$group, , , $priya, $sara] = cardiologyGroup();
    $assignment = $target($group, $sara);

    actingAsLearner($priya);

    putJson("/api/v1/me/assignments/{$assignment}/progress", ['status' => 'completed', 'score' => 101])
        ->assertNotFound()
        ->assertExactJson(['error' => ['code' => 'not_found', 'message' => 'Assignment not found.']]);

    expect(Progress::query()->count())->toBe(0);
})->with([
    'removed' => [fn (Group $group) => Assignment::factory()->for($group)->removed()->create()->id],
    'in a group the caller is not in' => [fn () => Assignment::factory()->create()->id],
    'a subset that does not target the caller' => [fn (Group $group, User $sara) => Assignment::factory()->for($group)->forLearners([$sara])->create()->id],
    'missing' => [fn () => 999999],
]);

test('an educator answers 403 and writes nothing', function () {
    [$group, $educator, $content] = cardiologyGroup();
    $assignment = Assignment::factory()->for($group)->for($content, 'contentItem')->create();

    actingAsEducator($educator);

    putJson("/api/v1/me/assignments/{$assignment->id}/progress", ['status' => 'in_progress'])
        ->assertForbidden()
        ->assertExactJson(['error' => ['code' => 'forbidden', 'message' => 'Forbidden.']]);

    expect(Progress::query()->count())->toBe(0);
});

test('a first write racing another one for the same pair leaves exactly one row', function (array $body, bool $changed, ProgressStatus $stored) {
    [$group, , $content, $priya] = cardiologyGroup();
    $assignment = Assignment::factory()->for($group)->for($content, 'contentItem')->create();
    $step = 'before insert';
    $insertOther = fn () => DB::table('progress')->insert([
        'assignment_id' => $assignment->id,
        'user_id' => $priya->id,
        'status' => 'in_progress',
        'started_at' => '2026-10-09 11:59:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // The other request's row outlives our rollback, like a committed row.
    DB::beforeExecuting(function (string $query) use (&$step, $insertOther) {
        if ($step === 'before insert' && str_starts_with($query, 'insert into `progress`')) {
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

    actingAsLearner($priya);

    $response = putJson("/api/v1/me/assignments/{$assignment->id}/progress", $body)->assertOk();
    $row = Progress::query()->sole();

    expect($step)->toBe('done')
        ->and($response->json('data.changed'))->toBe($changed)
        ->and($response->json('data.started_at'))->toBe('2026-10-09T13:59:00+02:00')
        ->and($row->status)->toBe($stored)
        ->and($row->started_at->toIso8601ZuluString())->toBe('2026-10-09T11:59:00Z');
})->with([
    'the same state' => [['status' => 'in_progress'], false, ProgressStatus::InProgress],
    'completed' => [['status' => 'completed', 'score' => 72], true, ProgressStatus::Completed],
]);
