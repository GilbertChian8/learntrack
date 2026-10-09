<?php

namespace App\Policies;

use App\Models\Assignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

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

    public function manage(User $user, Assignment $assignment): bool
    {
        return self::manageableBy($user)->whereKey($assignment->id)->exists();
    }
}
