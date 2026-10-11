<?php

namespace App\Queries;

use App\Models\User;
use App\Policies\GroupPolicy;
use Illuminate\Database\Eloquent\Builder;

/**
 * The learners an educator may add to a group, ordered by name, then id.
 * A search matches the name or the email anywhere, case-insensitive.
 */
class LearnerDirectory
{
    /**
     * @return Builder<User>
     */
    public static function for(User $educator, ?string $search = null): Builder
    {
        $learners = GroupPolicy::addableLearners($educator)
            ->orderBy('users.name')
            ->orderBy('users.id');

        if ($search !== null) {
            $pattern = '%'.addcslashes($search, '\\%_').'%';

            $learners->where(fn (Builder $query) => $query->whereLike('users.name', $pattern)->orWhereLike('users.email', $pattern));
        }

        return $learners;
    }
}
