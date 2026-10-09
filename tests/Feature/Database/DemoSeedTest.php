<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\artisan;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-10 12:00:00');
});

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

test('migrate:fresh --seed produces the demo data', function () {
    artisan('migrate:fresh', ['--seed' => true])->assertSuccessful();

    expect(DB::table('institutions')->count())->toBe(2)
        ->and(DB::table('users')->where('role', 'educator')->count())->toBe(6)
        ->and(DB::table('users')->where('role', 'learner')->count())->toBeGreaterThanOrEqual(250)
        ->and(DB::table('learner_groups')->count())->toBe(12)
        ->and(DB::table('content_items')->count())->toBe(40)
        ->and(DB::table('content_items')->distinct()->count('topic'))->toBe(6);

    $groups = DB::table('learner_groups')
        ->select('id')
        ->selectSub(fn ($query) => $query->from('group_educators')->selectRaw('count(*)')->whereColumn('group_id', 'learner_groups.id'), 'educators')
        ->selectSub(fn ($query) => $query->from('group_learners')->selectRaw('count(*)')->whereColumn('group_id', 'learner_groups.id'), 'learners')
        ->selectSub(fn ($query) => $query->from('assignments')->selectRaw('count(*)')->whereColumn('group_id', 'learner_groups.id')->whereNull('removed_at'), 'active_assignments')
        ->selectSub(fn ($query) => $query->from('assignments')->selectRaw('count(*)')->whereColumn('group_id', 'learner_groups.id')->whereNull('removed_at')->where('scope', 'learners'), 'subset_assignments')
        ->get();

    foreach ($groups as $group) {
        expect($group->educators)->toBeGreaterThanOrEqual(1)
            ->and($group->learners)->toBeGreaterThanOrEqual(20)->toBeLessThanOrEqual(60)
            ->and($group->active_assignments)->toBeGreaterThanOrEqual(5)->toBeLessThanOrEqual(12)
            ->and($group->subset_assignments)->toBeGreaterThanOrEqual(1);
    }

    $standing = collect(learnerStanding())->groupBy('group_id');

    expect($standing)->toHaveCount(12);

    foreach ($standing as $groupId => $learners) {
        $behindByOverdue = $learners->filter(fn (object $row) => $row->overdue > 0);
        $behindByScore = $learners->filter(fn (object $row) => $row->average_score !== null && (float) $row->average_score < 50);
        $onTrack = $learners->filter(fn (object $row) => $row->overdue == 0 && ($row->average_score === null || (float) $row->average_score >= 50));

        expect($behindByOverdue)->not->toBeEmpty("group {$groupId} has no learner behind by overdue work")
            ->and($behindByScore)->not->toBeEmpty("group {$groupId} has no learner behind by a low average")
            ->and($onTrack)->not->toBeEmpty("group {$groupId} has no learner on track");
    }

    $anna = DB::table('users')
        ->join('institutions', 'institutions.id', '=', 'users.institution_id')
        ->where('users.email', 'anna.keller@example.edu')
        ->first(['users.id', 'users.role', 'institutions.name as institution']);

    expect($anna->role)->toBe('educator')
        ->and($anna->institution)->toBe('Northside Medical School')
        ->and(DB::table('group_educators')
            ->join('learner_groups', 'learner_groups.id', '=', 'group_educators.group_id')
            ->where('group_educators.user_id', $anna->id)
            ->where('learner_groups.name', 'Cardiology Group A')
            ->exists())->toBeTrue();
});

test('the demo seed is deterministic', function () {
    $snapshot = function (): array {
        artisan('migrate:fresh', ['--seed' => true])->assertSuccessful();

        return [
            'learners' => DB::table('users')->where('role', 'learner')->orderBy('id')->limit(10)->pluck('name')->all(),
            'due_dates' => DB::table('assignments')->orderBy('id')->pluck('due_at')->all(),
        ];
    };

    $first = $snapshot();
    $second = $snapshot();

    expect($first['learners'])->toHaveCount(10)
        ->and($first['due_dates'])->not->toBeEmpty()
        ->and($second)->toBe($first);
});
