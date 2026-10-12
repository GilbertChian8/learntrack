<?php

namespace App\Queries;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A learner's pair rows across their groups, with the institution's time zone, in one query.
 * The rows are a derived table, so the cursor can page on due_at and assignment_id.
 */
class LearnerAssignments
{
    public static function for(User $learner): Builder
    {
        $pairRows = PairRows::forLearner($learner->id, CarbonImmutable::now())
            ->join('institutions as i', 'i.id', '=', 'g.institution_id')
            ->addSelect('i.timezone')
            ->reorder();

        return DB::query()
            ->fromSub($pairRows, 'pair_rows')
            ->orderBy('due_at')
            ->orderBy('assignment_id');
    }
}
