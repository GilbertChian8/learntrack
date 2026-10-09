<?php

use App\Models\Assignment;
use App\Models\ContentItem;
use App\Models\Group;
use App\Models\Progress;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\artisan;

/**
 * @return array{name: string, columns: list<string>, type: string, unique: bool, primary: bool}|null
 */
function findIndex(string $table, string $name): ?array
{
    return collect(Schema::getIndexes($table))->firstWhere('name', $name);
}

test('migrate:fresh creates the keys, the CHECK constraint and the generated column of the DDL', function () {
    artisan('migrate:fresh')->assertSuccessful();

    expect(findIndex('assignments', 'uq_assignments_active'))
        ->toMatchArray(['columns' => ['group_id', 'content_item_id', 'removed_key'], 'unique' => true])
        ->and(findIndex('assignments', 'idx_assignments_group'))
        ->toMatchArray(['columns' => ['group_id', 'removed_at', 'due_at'], 'unique' => false])
        ->and(findIndex('progress', 'uq_progress_pair'))
        ->toMatchArray(['columns' => ['assignment_id', 'user_id'], 'unique' => true]);

    $check = DB::selectOne(
        'select cc.check_clause
           from information_schema.table_constraints tc
           join information_schema.check_constraints cc
             on cc.constraint_schema = tc.constraint_schema and cc.constraint_name = tc.constraint_name
          where tc.table_schema = database() and tc.table_name = ? and tc.constraint_name = ?',
        ['progress', 'chk_progress_score'],
    );

    expect($check)->not->toBeNull();

    $removedKey = collect(Schema::getColumns('assignments'))->firstWhere('name', 'removed_key');

    expect($removedKey['type'])->toBe('datetime(6)')
        ->and($removedKey['generation']['type'])->toBe('stored')
        ->and($removedKey['generation']['expression'])->toContain('coalesce(`removed_at`');
});

test('a group has one active assignment per content item, and a removed one frees the slot', function () {
    $group = Group::factory()->create();
    $content = ContentItem::factory()->create();
    $first = Assignment::factory()->for($group)->for($content)->create();

    expect(fn () => Assignment::factory()->for($group)->for($content)->create())
        ->toThrow(UniqueConstraintViolationException::class);

    $first->update(['removed_at' => now()]);
    $second = Assignment::factory()->for($group)->for($content)->create();

    // Removed rows keep their own key, even when removed in the same second.
    $second->update(['removed_at' => now()]);
    Assignment::factory()->for($group)->for($content)->create();

    expect(Assignment::query()->where('group_id', $group->id)->count())->toBe(3)
        ->and(Assignment::query()->active()->where('group_id', $group->id)->count())->toBe(1);
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
