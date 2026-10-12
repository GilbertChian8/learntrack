<?php

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit/Policies', 'Unit/Queries');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| Helpers shared by the test files. They live here, not in a test file, so
| no two files can declare the same global function.
|
*/

/**
 * Tests authenticate with Passport::actingAs, as these two helpers do.
 */
function actingAsEducator(?User $educator = null): User
{
    $educator ??= User::factory()->educator()->create();

    Passport::actingAs($educator);

    return $educator;
}

function actingAsLearner(?User $learner = null): User
{
    $learner ??= User::factory()->learner()->create();

    Passport::actingAs($learner);

    return $learner;
}

function educatorOf(Group $group): User
{
    $educator = User::factory()->educator()->create(['institution_id' => $group->institution_id]);
    $group->educators()->attach($educator);

    return $educator;
}

function learnerIn(Group $group): User
{
    $learner = User::factory()->learner()->create(['institution_id' => $group->institution_id]);
    $group->learners()->attach($learner);

    return $learner;
}

/**
 * One row as PairRows returns it.
 *
 * @param  array<string, mixed>  $columns
 */
function pairRow(array $columns = []): stdClass
{
    return (object) [
        'group_id' => 12,
        'group_name' => 'Cardiology Group A',
        'user_id' => 101,
        'learner_name' => 'Sara Lindqvist',
        'assignment_id' => 41,
        'scope' => 'group',
        'due_at' => '2026-10-16 21:59:59',
        'content_item_id' => 8,
        'title' => 'Heart Failure Question Set',
        'topic' => 'Cardiology',
        'type' => 'question_set',
        'estimated_minutes' => 30,
        'progress_status' => null,
        'score' => null,
        'started_at' => null,
        'completed_at' => null,
        'status' => 'not_started',
        ...$columns,
    ];
}

/**
 * $learnerCount learners and $assignmentCount + 1 assignments in the group, with bulk inserts.
 * The last assignment targets five learners; about half of the pairs have progress.
 */
function seedLargeGroup(Group $group, User $educator, int $learnerCount, int $assignmentCount): void
{
    $stamp = now()->toDateTimeString();

    DB::table('users')->insert(array_map(fn (int $i) => [
        'institution_id' => $group->institution_id,
        'name' => "Learner {$i}",
        'email' => "learner{$i}@example.edu",
        'password' => 'unused',
        'role' => 'learner',
        'created_at' => $stamp,
        'updated_at' => $stamp,
    ], range(1, $learnerCount)));
    $learnerIds = DB::table('users')->where('role', 'learner')->orderBy('id')->pluck('id')->all();
    DB::table('group_learners')->insert(array_map(fn (int $id) => ['group_id' => $group->id, 'user_id' => $id, 'created_at' => $stamp], $learnerIds));

    DB::table('content_items')->insert(array_map(fn (int $i) => [
        'title' => "Content {$i}",
        'topic' => 'Cardiology',
        'type' => $i % 2 === 0 ? 'question_set' : 'article',
        'estimated_minutes' => 20,
        'created_at' => $stamp,
        'updated_at' => $stamp,
    ], range(0, $assignmentCount)));
    $contentIds = DB::table('content_items')->orderBy('id')->pluck('id')->all();

    DB::table('assignments')->insert(array_map(fn (int $i) => [
        'group_id' => $group->id,
        'content_item_id' => $contentIds[$i],
        'due_at' => now()->addDays($i % 30 - 15)->toDateTimeString(),
        'scope' => $i === $assignmentCount ? 'learners' : 'group',
        'created_by' => $educator->id,
        'removed_key' => 0,
        'created_at' => $stamp,
        'updated_at' => $stamp,
    ], array_keys($contentIds)));
    $assignmentIds = DB::table('assignments')->orderBy('id')->pluck('id')->all();
    DB::table('assignment_learners')->insert(array_map(
        fn (int $id) => ['assignment_id' => $assignmentIds[$assignmentCount], 'user_id' => $id, 'created_at' => $stamp],
        array_slice($learnerIds, 0, 5),
    ));

    $progress = [];
    foreach (array_slice($assignmentIds, 0, $assignmentCount) as $a => $assignmentId) {
        foreach ($learnerIds as $l => $learnerId) {
            if (($a + $l) % 2 === 1) {
                continue;
            }

            $completed = $l % 4 !== 0;
            $progress[] = [
                'assignment_id' => $assignmentId,
                'user_id' => $learnerId,
                'status' => $completed ? 'completed' : 'in_progress',
                'score' => $completed && $a % 2 === 0 ? ($a * 7 + $l * 3) % 101 : null,
                'started_at' => $stamp,
                'completed_at' => $completed ? now()->subMinutes($a * $learnerCount + $l)->toDateTimeString() : null,
            ];

            if (count($progress) === 1000) {
                DB::table('progress')->insert($progress);
                $progress = [];
            }
        }
    }
    DB::table('progress')->insert($progress);
}

/**
 * @return array{name: string, columns: list<string>, type: string, unique: bool, primary: bool}|null
 */
function findIndex(string $table, string $name): ?array
{
    return collect(Schema::getIndexes($table))->firstWhere('name', $name);
}

/**
 * Per group and learner: overdue, not completed pairs and the average score
 * of completed question sets, over the active assignments that target the
 * learner. Plain SQL, since BehindRule is ticket 09.
 *
 * @return list<object{group_id: int, user_id: int, overdue: int, average_score: string|null}>
 */
function learnerStanding(): array
{
    return DB::select(
        "select gl.group_id, gl.user_id,
                coalesce(sum(a.due_at < ? and (p.status is null or p.status <> 'completed')), 0) as overdue,
                avg(case when c.type = 'question_set' and p.status = 'completed' then p.score end) as average_score
           from group_learners gl
           left join assignments a on a.group_id = gl.group_id
                and a.removed_at is null
                and (a.scope = 'group' or exists (
                    select 1 from assignment_learners al where al.assignment_id = a.id and al.user_id = gl.user_id))
           left join content_items c on c.id = a.content_item_id
           left join progress p on p.assignment_id = a.id and p.user_id = gl.user_id
          group by gl.group_id, gl.user_id",
        [now()->toDateTimeString()],
    );
}
