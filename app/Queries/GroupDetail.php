<?php

namespace App\Queries;

use App\Exceptions\NotFoundException;
use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One group the educator teaches: its MyGroups row with the educators and
 * the learners, each ordered by name, then id.
 */
class GroupDetail
{
    /**
     * @throws NotFoundException
     */
    public static function for(User $educator, int $groupId): Group
    {
        return MyGroups::for($educator)
            ->whereKey($groupId)
            ->with([
                'educators' => fn (BelongsToMany $query) => $query->orderBy('users.name')->orderBy('users.id'),
                'learners' => fn (BelongsToMany $query) => $query->orderBy('users.name')->orderBy('users.id'),
            ])
            ->firstOr(fn () => throw NotFoundException::group());
    }
}
