<?php

use App\Models\Assignment;
use App\Models\Group;
use App\Models\Progress;
use App\Models\User;
use App\Queries\PairRows;
use Carbon\CarbonImmutable;

test('both variants return exactly the columns of the pair query', function () {
    $group = Group::factory()->create();
    $learner = learnerIn($group);
    Assignment::factory()->for($group)->create();
    $columns = [
        'group_id', 'group_name', 'user_id', 'learner_name',
        'assignment_id', 'scope', 'due_at',
        'content_item_id', 'title', 'topic', 'type', 'estimated_minutes',
        'progress_status', 'score', 'started_at', 'completed_at',
        'status',
    ];

    expect(array_keys((array) PairRows::forGroup($group->id)->sole()))->toBe($columns)
        ->and(array_keys((array) PairRows::forLearner($learner->id)->sole()))->toBe($columns);
});

test('the status is derived from the progress row and the due date', function (?string $progress, string $dueAt, string $status) {
    $group = Group::factory()->create();
    $learner = learnerIn($group);
    $assignment = Assignment::factory()->for($group)->create(['due_at' => $dueAt]);
    $row = Progress::factory()->for($assignment)->for($learner, 'user');

    match ($progress) {
        'in_progress' => $row->create(['started_at' => '2026-09-30 09:00:00']),
        'completed' => $row->completed(70)->create(['started_at' => '2026-09-30 09:00:00', 'completed_at' => '2026-09-30 10:00:00']),
        'completed late' => $row->completed(70)->create(['started_at' => '2026-09-30 09:00:00', 'completed_at' => '2026-10-08 10:00:00']),
        default => null,
    };

    expect(PairRows::forGroup($group->id, CarbonImmutable::parse('2026-10-09 08:00:00'))->sole()->status)->toBe($status);
})->with([
    'no row, not due' => [null, '2026-10-10 21:59:59', 'not_started'],
    'no row, due this very second' => [null, '2026-10-09 08:00:00', 'not_started'],
    'in progress, not due' => ['in_progress', '2026-10-10 21:59:59', 'in_progress'],
    'completed, not due' => ['completed', '2026-10-10 21:59:59', 'completed'],
    'completed, due date passed' => ['completed', '2026-10-01 21:59:59', 'completed'],
    'no row, due date passed' => [null, '2026-10-09 07:59:59', 'overdue'],
    'in progress, due date passed' => ['in_progress', '2026-10-01 21:59:59', 'overdue'],
    'completed after the due date' => ['completed late', '2026-10-01 21:59:59', 'completed'],
]);

test('without a given moment the database clock decides, not the application clock', function () {
    CarbonImmutable::setTestNow('2000-01-01 00:00:00');
    $group = Group::factory()->create();
    learnerIn($group);
    Assignment::factory()->for($group)->create(['due_at' => '2001-01-01 00:00:00']);

    expect(PairRows::forGroup($group->id)->sole()->status)->toBe('overdue')
        ->and(PairRows::forGroup($group->id, CarbonImmutable::now())->sole()->status)->toBe('not_started');
});

test('a subset assignment pairs only its learners and a removed assignment pairs nobody', function () {
    $group = Group::factory()->create();
    $targeted = learnerIn($group);
    learnerIn($group);
    $subset = Assignment::factory()->for($group)->forLearners([$targeted])->create();
    Assignment::factory()->for($group)->removed()->create();

    $pairs = PairRows::forGroup($group->id)->get()->map(fn (stdClass $row) => [$row->assignment_id, $row->user_id]);

    expect($pairs->all())->toBe([[$subset->id, $targeted->id]]);
});

test('an assignment that targets nobody gives one row with a null learner', function () {
    $group = Group::factory()->create();
    $leaver = learnerIn($group);
    $assignment = Assignment::factory()->for($group)->forLearners([$leaver])->create();
    $group->learners()->detach($leaver);

    $row = PairRows::forGroup($group->id)->sole();

    expect($row->assignment_id)->toBe($assignment->id)
        ->and($row->user_id)->toBeNull()
        ->and($row->learner_name)->toBeNull();
});

test('forLearner gives the pairs that target the learner across their groups, in due date order', function () {
    $learner = User::factory()->learner()->create();
    $first = Group::factory()->create(['institution_id' => $learner->institution_id]);
    $second = Group::factory()->create(['institution_id' => $learner->institution_id]);
    $notMine = Group::factory()->create(['institution_id' => $learner->institution_id]);
    $first->learners()->attach($learner);
    $second->learners()->attach($learner);
    $someoneElse = learnerIn($second);

    $later = Assignment::factory()->for($first)->create(['due_at' => '2026-10-20 21:59:59']);
    $sooner = Assignment::factory()->for($second)->forLearners([$learner])->create(['due_at' => '2026-10-12 21:59:59']);
    Assignment::factory()->for($second)->forLearners([$someoneElse])->create();
    Assignment::factory()->for($first)->removed()->create();
    Assignment::factory()->for($notMine)->create();

    $rows = PairRows::forLearner($learner->id)->get();

    expect($rows->pluck('assignment_id')->all())->toBe([$sooner->id, $later->id])
        ->and($rows->pluck('user_id')->unique()->all())->toBe([$learner->id])
        ->and($rows->pluck('learner_name')->unique()->all())->toBe([$learner->name])
        ->and(PairRows::forLearner($learner->id, [$first->id])->pluck('assignment_id')->all())->toBe([$later->id]);
});
