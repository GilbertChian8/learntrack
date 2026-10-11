<?php

namespace App\Queries;

use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A group the educator teaches, already found through Scope::group: its
 * MyGroups row with the educators and the learners, each ordered by name, then id.
 */
class GroupDetail
{
    public static function for(User $educator, Group $group): Group
    {
        return MyGroups::for($educator)
            ->whereKey($group->id)
            ->with([
                'educators' => fn (BelongsToMany $query) => $query->orderBy('users.name')->orderBy('users.id'),
                'learners' => fn (BelongsToMany $query) => $query->orderBy('users.name')->orderBy('users.id'),
            ])
            ->sole();
    }
}
