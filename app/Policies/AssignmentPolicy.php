<?php

namespace App\Policies;

use App\Enums\AssignmentScope;
use App\Models\Assignment;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class AssignmentPolicy
{
    /**
     * The active assignments of the groups the educator teaches.
     *
     * @return Builder<Assignment>
     */
    public static function manageableBy(User $educator): Builder
    {
        return Assignment::query()
            ->active()
            ->whereIn('assignments.group_id', GroupPolicy::taughtBy($educator)->select('learner_groups.id'));
    }

    /**
     * The active assignments in the learner's groups that target them:
     * scope group, or an assignment_learners row.
     *
     * @return Builder<Assignment>
     */
    public static function targeting(User $learner): Builder
    {
        return Assignment::query()
            ->active()
            ->whereExists(fn (QueryBuilder $query) => $query
                ->from('group_learners')
                ->whereColumn('group_learners.group_id', 'assignments.group_id')
                ->where('group_learners.user_id', $learner->id))
            ->where(fn (Builder $query) => $query
                ->where('assignments.scope', AssignmentScope::Group)
                ->orWhereExists(fn (QueryBuilder $query) => $query
                    ->from('assignment_learners')
                    ->whereColumn('assignment_learners.assignment_id', 'assignments.id')
                    ->where('assignment_learners.user_id', $learner->id)));
    }

    public function manage(User $user, Assignment $assignment): Response
    {
        return self::manageableBy($user)->whereKey($assignment->id)->exists()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function recordProgress(User $learner, Assignment $assignment): Response
    {
        return self::targeting($learner)->whereKey($assignment->id)->exists()
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
