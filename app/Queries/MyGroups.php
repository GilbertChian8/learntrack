<?php

namespace App\Queries;

use App\Models\Assignment;
use App\Models\Group;
use App\Models\Institution;
use App\Models\User;
use App\Policies\GroupPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The groups an educator teaches, ordered by name then id, each with its
 * institution, learner_count and assignment_count (active only). One query.
 */
class MyGroups
{
    /**
     * @return Builder<Group>
     */
    public static function for(User $educator): Builder
    {
        $taughtGroupIds = GroupPolicy::taughtBy($educator)->select('learner_groups.id');

        $learnerCounts = DB::table('group_learners')
            ->select('group_id', DB::raw('count(*) as learner_count'))
            ->whereIn('group_id', $taughtGroupIds)
            ->groupBy('group_id');

        $assignmentCounts = Assignment::query()
            ->active()
            ->select('group_id', DB::raw('count(*) as assignment_count'))
            ->whereIn('group_id', $taughtGroupIds)
            ->groupBy('group_id');

        return GroupPolicy::taughtBy($educator)
            ->join('institutions', 'institutions.id', '=', 'learner_groups.institution_id')
            ->leftJoinSub($learnerCounts, 'learner_counts', 'learner_counts.group_id', '=', 'learner_groups.id')
            ->leftJoinSub($assignmentCounts, 'assignment_counts', 'assignment_counts.group_id', '=', 'learner_groups.id')
            ->select('learner_groups.*', 'institutions.name as institution_name', 'institutions.timezone as institution_timezone')
            ->selectRaw('coalesce(learner_counts.learner_count, 0) as learner_count')
            ->selectRaw('coalesce(assignment_counts.assignment_count, 0) as assignment_count')
            ->withCasts(['learner_count' => 'integer', 'assignment_count' => 'integer'])
            ->orderBy('learner_groups.name')
            ->orderBy('learner_groups.id')
            ->afterQuery(fn (Collection $groups) => $groups->each(fn (Model $group) => $group->setRelation(
                'institution',
                (new Institution)->newFromBuilder([
                    'id' => $group->getAttribute('institution_id'),
                    'name' => $group->getAttribute('institution_name'),
                    'timezone' => $group->getAttribute('institution_timezone'),
                ]),
            )));
    }
}
