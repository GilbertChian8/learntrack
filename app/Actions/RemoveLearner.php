<?php

namespace App\Actions;

use App\Enums\AuditAction;
use App\Enums\AuditChannel;
use App\Models\Group;
use App\Models\User;
use App\Support\AuditWriter;
use Illuminate\Support\Facades\DB;

/**
 * Deletes the membership only. The learner's progress rows stay.
 */
class RemoveLearner
{
    /**
     * @return bool whether a membership was removed
     */
    public function handle(User $educator, Group $group, int $userId, AuditChannel $channel): bool
    {
        return DB::transaction(function () use ($educator, $group, $userId, $channel): bool {
            if ($group->learners()->detach($userId) === 0) {
                return false;
            }

            AuditWriter::record($educator, $channel, AuditAction::GroupLearnerRemoved, $group, ['user_id' => $userId]);

            return true;
        });
    }
}
