<?php

namespace App\Queries;

use App\Enums\AssignmentScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * One row per learner and active assignment that targets them, with the derived
 * status, in due date order. A given $now replaces UTC_TIMESTAMP().
 */
class PairRows
{
    /**
     * An assignment that targets nobody gives one row with a null learner.
     */
    public static function forGroup(int $groupId, ?CarbonImmutable $now = null): Builder
    {
        return self::columns(DB::table('assignments as a'), $now)
            ->join('learner_groups as g', 'g.id', '=', 'a.group_id')
            ->join('content_items as c', 'c.id', '=', 'a.content_item_id')
            ->leftJoin('group_learners as gl', fn (JoinClause $join) => self::targeting(
                $join->on('gl.group_id', '=', 'a.group_id'),
            ))
            ->leftJoin('users as u', 'u.id', '=', 'gl.user_id')
            ->leftJoin('progress as p', fn (JoinClause $join) => $join
                ->on('p.assignment_id', '=', 'a.id')
                ->on('p.user_id', '=', 'gl.user_id'))
            ->where('a.group_id', $groupId)
            ->whereNull('a.removed_at')
            ->orderBy('a.due_at')
            ->orderBy('a.id');
    }

    /**
     * The learner's groups come from their group_learners rows, restricted to $groupIds when given.
     *
     * @param  list<int>|null  $groupIds
     */
    public static function forLearner(int $learnerId, ?array $groupIds = null, ?CarbonImmutable $now = null): Builder
    {
        return self::columns(DB::table('group_learners as gl'), $now)
            ->join('assignments as a', fn (JoinClause $join) => self::targeting(
                $join->on('a.group_id', '=', 'gl.group_id')->whereNull('a.removed_at'),
            ))
            ->join('learner_groups as g', 'g.id', '=', 'a.group_id')
            ->join('content_items as c', 'c.id', '=', 'a.content_item_id')
            ->join('users as u', 'u.id', '=', 'gl.user_id')
            ->leftJoin('progress as p', fn (JoinClause $join) => $join
                ->on('p.assignment_id', '=', 'a.id')
                ->on('p.user_id', '=', 'gl.user_id'))
            ->where('gl.user_id', $learnerId)
            ->when($groupIds !== null, fn (Builder $query) => $query->whereIn('gl.group_id', $groupIds))
            ->orderBy('a.due_at')
            ->orderBy('a.id');
    }

    private static function columns(Builder $query, ?CarbonImmutable $now): Builder
    {
        return $query
            ->select([
                'a.group_id', 'g.name as group_name',
                'gl.user_id', 'u.name as learner_name',
                'a.id as assignment_id', 'a.scope', 'a.due_at',
                'c.id as content_item_id', 'c.title', 'c.topic', 'c.type', 'c.estimated_minutes',
                'p.status as progress_status', 'p.score', 'p.started_at', 'p.completed_at',
            ])
            ->selectRaw(
                "case
                    when p.status = 'completed' then 'completed'
                    when a.due_at < coalesce(cast(? as datetime), utc_timestamp()) then 'overdue'
                    when p.status = 'in_progress' then 'in_progress'
                    else 'not_started'
                end as status",
                [$now?->utc()->format('Y-m-d H:i:s')],
            );
    }

    private static function targeting(JoinClause $join): JoinClause
    {
        return $join->where(fn (Builder $query) => $query
            ->where('a.scope', AssignmentScope::Group->value)
            ->orWhereExists(fn (Builder $query) => $query
                ->selectRaw('1')
                ->from('assignment_learners as al')
                ->whereColumn('al.assignment_id', 'a.id')
                ->whereColumn('al.user_id', 'gl.user_id')));
    }
}
