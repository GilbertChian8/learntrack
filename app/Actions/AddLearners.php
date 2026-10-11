<?php

namespace App\Actions;

use App\Actions\Results\AddLearnersResult;
use App\Enums\AuditAction;
use App\Enums\AuditChannel;
use App\Models\Group;
use App\Models\User;
use App\Policies\GroupPolicy;
use App\Support\AuditWriter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddLearners
{
    /**
     * @param  list<int>  $userIds
     *
     * @throws ValidationException
     */
    public function handle(User $educator, Group $group, array $userIds, AuditChannel $channel): AddLearnersResult
    {
        $learners = GroupPolicy::addableLearners($educator)
            ->whereKey($userIds)
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->get(['users.id', 'users.name']);

        $this->ensureEveryIdIsALearner($userIds, $learners);

        try {
            return $this->add($educator, $group, $learners, $channel);
        } catch (UniqueConstraintViolationException) {
            return $this->add($educator, $group, $learners, $channel);
        }
    }

    /**
     * @param  list<int>  $userIds
     * @param  Collection<int, User>  $learners
     *
     * @throws ValidationException
     */
    private function ensureEveryIdIsALearner(array $userIds, Collection $learners): void
    {
        $errors = collect($userIds)
            ->diff($learners->modelKeys())
            ->mapWithKeys(fn (int $userId, int $index) => [
                "user_ids.{$index}" => "User {$userId} is not a learner of your institution.",
            ]);

        if ($errors->isNotEmpty()) {
            throw ValidationException::withMessages($errors->all());
        }
    }

    /**
     * @param  Collection<int, User>  $learners
     */
    private function add(User $educator, Group $group, Collection $learners, AuditChannel $channel): AddLearnersResult
    {
        return DB::transaction(function () use ($educator, $group, $learners, $channel): AddLearnersResult {
            $memberIds = $group->learners()->whereKey($learners->modelKeys())->pluck('users.id');

            [$alreadyMembers, $added] = $learners->partition(fn (User $learner) => $memberIds->contains($learner->id));

            $group->learners()->attach($added->modelKeys());

            foreach ($added as $learner) {
                AuditWriter::record($educator, $channel, AuditAction::GroupLearnerAdded, $group, ['user_id' => $learner->id]);
            }

            return new AddLearnersResult($added->values(), $alreadyMembers->values());
        });
    }
}
