<?php

namespace App\Policies;

use App\Models\Group;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class GroupPolicy
{
    /**
     * The groups with a group_educators row for the educator.
     *
     * @return Builder<Group>
     */
    public static function taughtBy(User $educator): Builder
    {
        return Group::query()->whereExists(fn (QueryBuilder $query) => $query
            ->from('group_educators')
            ->whereColumn('group_educators.group_id', 'learner_groups.id')
            ->where('group_educators.user_id', $educator->id));
    }

    public function view(User $user, Group $group): Response
    {
        return self::taughtBy($user)->whereKey($group->id)->exists()
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
