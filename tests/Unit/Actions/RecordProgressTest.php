<?php

use App\Actions\RecordProgress;
use App\Enums\ContentType;
use App\Enums\ProgressStatus;
use App\Exceptions\AlreadyCompletedException;
use App\Exceptions\NotFoundException;
use App\Models\Assignment;
use App\Models\AuditEntry;
use App\Models\ContentItem;
use App\Models\Progress;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-09 12:00:00');
});

test('every row of the transition table', function (?array $current, ProgressStatus $status, ?int $score, ?bool $changed, array $stored) {
    [$group, , $content, $priya] = cardiologyGroup();
    $assignment = Assignment::factory()->for($group)->for($content, 'contentItem')->create(['due_at' => '2026-10-16 21:59:59']);

    if ($current !== null) {
        Progress::factory()->for($assignment)->for($priya, 'user')->create(['started_at' => '2026-10-08 06:00:00', ...$current]);
    }

    try {
        $result = app(RecordProgress::class)->handle($priya, $assignment, $status, $score);
        expect($result->changed)->toBe($changed);
    } catch (AlreadyCompletedException) {
        expect($changed)->toBeNull();
    }

    $row = Progress::query()->sole();

    expect([$row->status->value, $row->score, $row->started_at->toDateTimeString(), $row->completed_at?->toDateTimeString()])->toBe($stored)
        ->and(AuditEntry::query()->count())->toBe(0);
})->with([
    'none, in_progress' => [null, ProgressStatus::InProgress, null, true, ['in_progress', null, '2026-10-09 12:00:00', null]],
    'in_progress, in_progress' => [[], ProgressStatus::InProgress, null, false, ['in_progress', null, '2026-10-08 06:00:00', null]],
    'completed, in_progress' => [['status' => 'completed', 'score' => 72, 'completed_at' => '2026-10-08 07:00:00'], ProgressStatus::InProgress, null, null, ['completed', 72, '2026-10-08 06:00:00', '2026-10-08 07:00:00']],
    'none, completed' => [null, ProgressStatus::Completed, 72, true, ['completed', 72, '2026-10-09 12:00:00', '2026-10-09 12:00:00']],
    'in_progress, completed' => [[], ProgressStatus::Completed, 72, true, ['completed', 72, '2026-10-08 06:00:00', '2026-10-09 12:00:00']],
    'completed, completed with the same score' => [['status' => 'completed', 'score' => 72, 'completed_at' => '2026-10-08 07:00:00'], ProgressStatus::Completed, 72, false, ['completed', 72, '2026-10-08 06:00:00', '2026-10-08 07:00:00']],
    'completed, completed with another score' => [['status' => 'completed', 'score' => 72, 'completed_at' => '2026-10-08 07:00:00'], ProgressStatus::Completed, 73, null, ['completed', 72, '2026-10-08 06:00:00', '2026-10-08 07:00:00']],
]);

test('the result carries the row with the derived status', function () {
    [$group, , $content, $priya] = cardiologyGroup();
    $assignment = Assignment::factory()->for($group)->for($content, 'contentItem')->create(['due_at' => '2026-10-05 21:59:59']);

    $result = app(RecordProgress::class)->handle($priya, $assignment, ProgressStatus::InProgress, null);

    expect($result->changed)->toBeTrue()
        ->and($result->row->assignment_id)->toBe($assignment->id)
        ->and($result->row->status)->toBe('overdue')
        ->and($result->row->timezone)->toBe('Europe/Berlin');
});

test('the conflict carries the existing row', function () {
    [$group, , $content, $priya] = cardiologyGroup();
    $assignment = Assignment::factory()->for($group)->for($content, 'contentItem')->create();
    Progress::factory()->for($assignment)->for($priya, 'user')->completed(72)->create(['completed_at' => '2026-10-08 07:00:00']);

    try {
        app(RecordProgress::class)->handle($priya, $assignment, ProgressStatus::Completed, 40);
        throw new RuntimeException('No exception.');
    } catch (AlreadyCompletedException $exception) {
        expect($exception->getMessage())->toBe('This assignment is already completed with a score of 72.')
            ->and($exception->code())->toBe('already_completed')
            ->and($exception->details())->toBe(['status' => 'completed', 'score' => 72, 'completed_at' => '2026-10-08T09:00:00+02:00']);
    }
});

test('the score rules throw on score and write nothing', function (ContentType $type, ProgressStatus $status, ?int $score, string $message) {
    [$group, , , $priya] = cardiologyGroup();
    $assignment = Assignment::factory()->for($group)->for(ContentItem::factory()->create(['type' => $type]), 'contentItem')->create();

    try {
        app(RecordProgress::class)->handle($priya, $assignment, $status, $score);
        throw new RuntimeException('No exception.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['score' => [$message]]);
    }

    expect(Progress::query()->count())->toBe(0);
})->with([
    'a question set completed without a score' => [ContentType::QuestionSet, ProgressStatus::Completed, null, 'A completed question set needs a score.'],
    'an article completed with a score' => [ContentType::Article, ProgressStatus::Completed, 80, 'An article does not take a score.'],
    'a question set in progress with a score' => [ContentType::QuestionSet, ProgressStatus::InProgress, 50, 'A score is only accepted with the status completed.'],
    'an article in progress with a score' => [ContentType::Article, ProgressStatus::InProgress, 50, 'A score is only accepted with the status completed.'],
]);

test('an assignment removed after the lookup throws not found and writes nothing', function () {
    [$group, , $content, $priya] = cardiologyGroup();
    $assignment = Assignment::factory()->for($group)->for($content, 'contentItem')->create();
    $assignment->update(['removed_at' => now(), 'removed_key' => $assignment->id]);

    expect(fn () => app(RecordProgress::class)->handle($priya, $assignment, ProgressStatus::InProgress, null))
        ->toThrow(NotFoundException::class, 'Assignment not found.')
        ->and(Progress::query()->count())->toBe(0);
});

test('a learner removed from the group after the lookup throws not found and writes nothing', function () {
    [$group, , $content, $priya] = cardiologyGroup();
    $assignment = Assignment::factory()->for($group)->for($content, 'contentItem')->create();
    $group->learners()->detach($priya);

    expect(fn () => app(RecordProgress::class)->handle($priya, $assignment, ProgressStatus::InProgress, null))
        ->toThrow(NotFoundException::class, 'Assignment not found.')
        ->and(Progress::query()->count())->toBe(0);
});

test('a learner removed from the group after the write throws not found and keeps nothing', function () {
    [$group, , $content, $priya] = cardiologyGroup();
    $assignment = Assignment::factory()->for($group)->for($content, 'contentItem')->create();
    $step = 'before read';
    $savedBeforeRead = null;

    DB::beforeExecuting(function (string $query) use (&$step, &$savedBeforeRead, $group, $priya) {
        if ($step === 'before read' && str_contains($query, 'as `pair_rows`')) {
            $step = 'done';
            $savedBeforeRead = Progress::query()->count();
            $group->learners()->detach($priya);
        }
    });

    expect(fn () => app(RecordProgress::class)->handle($priya, $assignment, ProgressStatus::InProgress, null))
        ->toThrow(NotFoundException::class, 'Assignment not found.')
        ->and($step)->toBe('done')
        ->and($savedBeforeRead)->toBe(1)
        ->and(Progress::query()->count())->toBe(0);
});
