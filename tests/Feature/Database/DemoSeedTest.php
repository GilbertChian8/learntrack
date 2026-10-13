<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\artisan;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-10 12:00:00');
});

test('migrate:fresh --seed produces the demo data', function () {
    artisan('migrate:fresh', ['--seed' => true])->assertSuccessful();

    expect(DB::table('institutions')->count())->toBe(2)
        ->and(DB::table('users')->where('role', 'educator')->count())->toBe(7)
        ->and(DB::table('users')->where('role', 'learner')->count())->toBeGreaterThanOrEqual(250)
        ->and(DB::table('learner_groups')->count())->toBe(13)
        ->and(DB::table('content_items')->count())->toBe(100)
        ->and(DB::table('content_items')->distinct()->count('topic'))->toBe(6);

    $groups = DB::table('learner_groups')
        ->where('name', '!=', 'Full Size Group')
        ->select('id')
        ->selectSub(fn ($query) => $query->from('group_educators')->selectRaw('count(*)')->whereColumn('group_id', 'learner_groups.id'), 'educators')
        ->selectSub(fn ($query) => $query->from('group_learners')->selectRaw('count(*)')->whereColumn('group_id', 'learner_groups.id'), 'learners')
        ->selectSub(fn ($query) => $query->from('assignments')->selectRaw('count(*)')->whereColumn('group_id', 'learner_groups.id')->whereNull('removed_at'), 'active_assignments')
        ->selectSub(fn ($query) => $query->from('assignments')->selectRaw('count(*)')->whereColumn('group_id', 'learner_groups.id')->whereNull('removed_at')->where('scope', 'learners'), 'subset_assignments')
        ->get();

    expect($groups)->toHaveCount(12);

    foreach ($groups as $group) {
        expect($group->educators)->toBeGreaterThanOrEqual(1)
            ->and($group->learners)->toBeGreaterThanOrEqual(20)->toBeLessThanOrEqual(60)
            ->and($group->active_assignments)->toBeGreaterThanOrEqual(5)->toBeLessThanOrEqual(12)
            ->and($group->subset_assignments)->toBeGreaterThanOrEqual(1);
    }

    $standing = collect(learnerStanding())->groupBy('group_id');

    expect($standing)->toHaveCount(13);

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

test('the full-size group comes last and leaves the demo groups as they were', function () {
    artisan('migrate:fresh', ['--seed' => true])->assertSuccessful();

    $sizes = DB::table('learner_groups')->orderBy('id')->get(['id', 'name'])
        ->mapWithKeys(fn (object $group) => [$group->name => [
            DB::table('group_learners')->where('group_id', $group->id)->count(),
            DB::table('assignments')->where('group_id', $group->id)->whereNull('removed_at')->count(),
        ]])
        ->all();

    expect($sizes)->toBe([
        'Cardiology Group A' => [43, 6],
        'Cardiology Group B' => [26, 6],
        'Pulmonology Group A' => [39, 10],
        'Nephrology Group A' => [20, 7],
        'Neurology Group A' => [50, 5],
        'Pediatrics Group A' => [55, 8],
        'Endocrinology Group A' => [45, 7],
        'Cardiology Group C' => [58, 8],
        'Pulmonology Group B' => [56, 7],
        'Nephrology Group B' => [48, 7],
        'Neurology Group B' => [29, 11],
        'Pediatrics Group B' => [50, 12],
        'Full Size Group' => [60, 100],
    ]);
});

test('the full-size group has 6,000 pairs, new learners, its own educator and a few learners behind', function () {
    artisan('migrate:fresh', ['--seed' => true])->assertSuccessful();

    $group = DB::table('learner_groups')
        ->join('institutions', 'institutions.id', '=', 'learner_groups.institution_id')
        ->where('learner_groups.name', 'Full Size Group')
        ->sole(['learner_groups.id', 'institutions.name as institution']);
    $educator = DB::table('users')->where('email', 'full.size.educator@example.edu')->sole(['id', 'role']);
    $assignments = DB::table('assignments')->where('group_id', $group->id)->whereNull('removed_at');
    $pairs = DB::select(
        "select a.group_id, count(*) as pairs
           from assignments a
           join group_learners gl on gl.group_id = a.group_id
          where a.removed_at is null
            and (a.scope = 'group' or exists (
                select 1 from assignment_learners al where al.assignment_id = a.id and al.user_id = gl.user_id))
          group by a.group_id
          order by pairs desc",
    );
    $learnersInOtherGroups = DB::table('group_learners as mine')
        ->join('group_learners as other', 'other.user_id', '=', 'mine.user_id')
        ->where('mine.group_id', $group->id)
        ->where('other.group_id', '!=', $group->id)
        ->count();
    $standing = collect(learnerStanding())->where('group_id', $group->id);
    $behindByOverdue = $standing->filter(fn (object $row) => $row->overdue > 0);
    $behindByScore = $standing->filter(fn (object $row) => $row->average_score !== null && (float) $row->average_score < 50);

    expect($group->institution)->toBe('Riverside College of Medicine')
        ->and($educator->role)->toBe('educator')
        ->and(DB::table('group_educators')->where('group_id', $group->id)->pluck('user_id')->all())->toBe([$educator->id])
        ->and(DB::table('group_educators')->where('user_id', $educator->id)->count())->toBe(1)
        ->and((clone $assignments)->where('scope', 'group')->count())->toBe(100)
        ->and((clone $assignments)->distinct()->count('content_item_id'))->toBe(100)
        ->and($pairs[0]->group_id)->toBe($group->id)
        ->and((int) $pairs[0]->pairs)->toBe(6000)
        ->and($learnersInOtherGroups)->toBe(0)
        ->and($standing)->toHaveCount(60)
        ->and($behindByOverdue)->toHaveCount(5)
        ->and($behindByScore)->toHaveCount(4)
        ->and($behindByOverdue->keys()->merge($behindByScore->keys())->unique())->toHaveCount(8);
});

test('the demo seed is deterministic', function () {
    // The clock stays frozen (beforeEach) on purpose: names, memberships, assignments
    // and scores come from the fixed Faker seed alone, but due dates are relative to
    // the seeding day, so two runs only give the same due dates on the same clock.
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
