<?php

namespace App\Queries;

use App\Enums\AssignmentScope;
use App\Models\Assignment;
use App\Models\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The active assignments of a group, ordered by due_at then id, each with its
 * content, targeted_count and the targeted learners who are still members.
 */
class GroupAssignments
{
    /**
     * @return Builder<Assignment>
     */
    public static function for(Group $group): Builder
    {
        return Assignment::query()
            ->active()
            ->where('assignments.group_id', $group->id)
            ->select('assignments.*')
            ->selectSub(self::targetedCount(), 'targeted_count')
            ->withCasts(['targeted_count' => 'integer'])
            ->with([
                'contentItem',
                'learners' => fn (BelongsToMany $query) => $query
                    ->whereExists(fn (QueryBuilder $query) => $query
                        ->from('group_learners')
                        ->whereColumn('group_learners.user_id', 'users.id')
                        ->where('group_learners.group_id', $group->id))
                    ->orderBy('users.name')
                    ->orderBy('users.id'),
            ])
            ->orderBy('assignments.due_at')
            ->orderBy('assignments.id')
            ->afterQuery(function (Collection $assignments) use ($group): void {
                $group->loadMissing('institution');
                $assignments->each(fn (Model $assignment) => $assignment->setRelation('group', $group));
            });
    }

    /**
     * The current members an assignment targets: all of them for scope group,
     * those with an assignment_learners row for scope learners.
     */
    private static function targetedCount(): QueryBuilder
    {
        return DB::table('group_learners')
            ->selectRaw('count(*)')
            ->whereColumn('group_learners.group_id', 'assignments.group_id')
            ->where(fn (QueryBuilder $query) => $query
                ->where('assignments.scope', AssignmentScope::Group)
                ->orWhereExists(fn (QueryBuilder $query) => $query
                    ->from('assignment_learners')
                    ->whereColumn('assignment_learners.assignment_id', 'assignments.id')
                    ->whereColumn('assignment_learners.user_id', 'group_learners.user_id')));
    }
}
