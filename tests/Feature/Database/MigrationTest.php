<?php

use App\Models\Assignment;
use App\Models\ContentItem;
use App\Models\Group;
use App\Models\Progress;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\artisan;

test('migrate:fresh creates the keys and the CHECK constraints of the DDL', function () {
    artisan('migrate:fresh')->assertSuccessful();

    expect(findIndex('assignments', 'uq_assignments_active'))
        ->toMatchArray(['columns' => ['group_id', 'content_item_id', 'removed_key'], 'unique' => true])
        ->and(findIndex('assignments', 'idx_assignments_group'))
        ->toMatchArray(['columns' => ['group_id', 'removed_at', 'due_at'], 'unique' => false])
        ->and(findIndex('progress', 'uq_progress_pair'))
        ->toMatchArray(['columns' => ['assignment_id', 'user_id'], 'unique' => true]);

    $checks = collect(DB::select(
        "select concat(table_name, '.', constraint_name) as name
           from information_schema.table_constraints
          where table_schema = database() and constraint_type = 'CHECK'",
    ))->pluck('name');

    expect($checks)->toContain('assignments.chk_assignments_removed', 'progress.chk_progress_score');
});

test('a group has one active assignment per content item, and a removed one frees the slot', function () {
    $group = Group::factory()->create();
    $content = ContentItem::factory()->create();
    $first = Assignment::factory()->for($group)->for($content)->create();

    expect(fn () => Assignment::factory()->for($group)->for($content)->create())
        ->toThrow(UniqueConstraintViolationException::class);

    $first->update(['removed_at' => now(), 'removed_key' => $first->id]);
    $second = Assignment::factory()->for($group)->for($content)->create();

    // Removed rows never collide with each other: each carries its own id.
    $second->update(['removed_at' => now(), 'removed_key' => $second->id]);
    Assignment::factory()->for($group)->for($content)->create();

    expect(Assignment::query()->where('group_id', $group->id)->count())->toBe(3)
        ->and(Assignment::query()->active()->where('group_id', $group->id)->count())->toBe(1);
});

test('removed_at and removed_key are refused by the CHECK constraint unless set together', function () {
    $assignment = Assignment::factory()->create();

    expect(fn () => Assignment::query()->whereKey($assignment->id)->update(['removed_at' => now()]))
        ->toThrow(QueryException::class, 'chk_assignments_removed')
        ->and(fn () => Assignment::query()->whereKey($assignment->id)->update(['removed_key' => $assignment->id]))
        ->toThrow(QueryException::class, 'chk_assignments_removed');
});

test('a progress score above 100 is refused by the CHECK constraint', function () {
    $assignment = Assignment::factory()->create();

    expect(fn () => Progress::factory()->for($assignment)->completed(101)->create())
        ->toThrow(QueryException::class, 'chk_progress_score');

    expect(Progress::factory()->for($assignment)->completed(100)->create()->score)->toBe(100)
        ->and(Progress::factory()->for($assignment)->completed(null)->create()->score)->toBeNull();
});

test('a learner has one progress row per assignment', function () {
    $assignment = Assignment::factory()->create();
    $learner = User::factory()->learner()->create();

    Progress::factory()->for($assignment)->for($learner)->create();

    expect(fn () => Progress::factory()->for($assignment)->for($learner)->completed(80)->create())
        ->toThrow(UniqueConstraintViolationException::class);
});
