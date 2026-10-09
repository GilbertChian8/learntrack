<?php

use App\Exceptions\NotFoundException;
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
    ->in('Feature', 'Unit/Policies');

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
 * The subject of the NotFoundException the lookup throws, or null when it throws none.
 */
function notFoundSubject(Closure $lookup): ?string
{
    try {
        $lookup();
    } catch (NotFoundException $exception) {
        return $exception->subject;
    }

    return null;
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
