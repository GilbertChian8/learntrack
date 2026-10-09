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
