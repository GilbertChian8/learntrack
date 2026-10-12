<?php

namespace App\Actions;

use App\Actions\Results\AssignResult;
use App\Enums\AssignmentScope;
use App\Enums\AssignOutcome;
use App\Enums\AuditAction;
use App\Enums\AuditChannel;
use App\Exceptions\AlreadyAssignedException;
use App\Models\Assignment;
use App\Models\ContentItem;
use App\Models\Group;
use App\Models\User;
use App\Queries\GroupAssignments;
use App\Support\AuditWriter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The AssignContent rules of the TDD. The caller has already checked rule 1's
 * group, content item and due date; the learner check is here.
 */
class AssignContent
{
    /**
     * @param  list<int>  $learnerIds  empty for the whole group
     *
     * @throws ValidationException
     * @throws AlreadyAssignedException
     */
    public function handle(User $educator, Group $group, ContentItem $item, CarbonImmutable $dueAt, array $learnerIds, AuditChannel $channel): AssignResult
    {
        $members = $group->learners()->orderBy('users.name')->orderBy('users.id')->get(['users.id', 'users.name']);

        $this->ensureEveryIdIsAMember($learnerIds, $members);

        $named = $learnerIds === [] ? $members : $members->whereIn('id', $learnerIds)->values();
        $assign = fn (): AssignResult => DB::transaction(
            fn (): AssignResult => $this->applyRules($educator, $group, $item, $dueAt, $named, $learnerIds !== [], $channel),
        );

        try {
            return $assign();
        } catch (UniqueConstraintViolationException) {
            return $assign();
        }
    }

    /**
     * @param  list<int>  $learnerIds
     * @param  Collection<int, User>  $members
     *
     * @throws ValidationException
     */
    private function ensureEveryIdIsAMember(array $learnerIds, Collection $members): void
    {
        $errors = collect($learnerIds)
            ->diff($members->modelKeys())
            ->mapWithKeys(fn (int $learnerId, int $index) => [
                "learner_ids.{$index}" => "Learner {$learnerId} is not a member of this group.",
            ]);

        if ($errors->isNotEmpty()) {
            throw ValidationException::withMessages($errors->all());
        }
    }

    /**
     * @param  Collection<int, User>  $named  the named learners, or every member when none are named
     *
     * @throws AlreadyAssignedException
     */
    private function applyRules(User $educator, Group $group, ContentItem $item, CarbonImmutable $dueAt, Collection $named, bool $namesLearners, AuditChannel $channel): AssignResult
    {
        $existing = $this->lockActiveAssignment($group, $item);

        if ($existing === null) {
            return $this->create($educator, $group, $item, $dueAt, $named, $namesLearners, $channel);
        }

        if (! $existing->due_at->equalTo($dueAt)) {
            throw new AlreadyAssignedException($this->show($group, $existing->id));
        }

        if ($existing->scope === AssignmentScope::Group) {
            return $this->result(AssignOutcome::Unchanged, $group, $existing->id, new Collection, $named);
        }

        $targetedIds = $existing->learners()->sharedLock()->pluck('users.id');
        [$alreadyHadIt, $added] = $named->partition(fn (User $learner) => $targetedIds->contains($learner->id));

        if ($namesLearners && $added->isEmpty()) {
            return $this->result(AssignOutcome::Unchanged, $group, $existing->id, $added, $alreadyHadIt);
        }

        $changes = ['learner_ids' => self::sortedIds($added)];

        if ($namesLearners) {
            $existing->learners()->attach($added->modelKeys());
        } else {
            $existing->update(['scope' => AssignmentScope::Group]);
            $changes['scope'] = ['from' => AssignmentScope::Learners->value, 'to' => AssignmentScope::Group->value];
        }

        AuditWriter::record($educator, $channel, AuditAction::AssignmentLearnersAdded, $existing, $changes);

        return $this->result(AssignOutcome::LearnersAdded, $group, $existing->id, $added, $alreadyHadIt);
    }

    private function lockActiveAssignment(Group $group, ContentItem $item): ?Assignment
    {
        return Assignment::query()
            ->active()
            ->where('group_id', $group->id)
            ->where('content_item_id', $item->id)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param  Collection<int, User>  $named
     */
    private function create(User $educator, Group $group, ContentItem $item, CarbonImmutable $dueAt, Collection $named, bool $namesLearners, AuditChannel $channel): AssignResult
    {
        $assignment = Assignment::query()->create([
            'group_id' => $group->id,
            'content_item_id' => $item->id,
            'due_at' => $dueAt->utc(),
            'scope' => $namesLearners ? AssignmentScope::Learners : AssignmentScope::Group,
            'created_by' => $educator->id,
        ]);

        if ($namesLearners) {
            $assignment->learners()->attach($named->modelKeys());
        }

        AuditWriter::record($educator, $channel, AuditAction::AssignmentCreated, $assignment, [
            'content_item_id' => $item->id,
            'due_at' => $dueAt->toIso8601ZuluString(),
            'scope' => $assignment->scope->value,
            'learner_ids' => $namesLearners ? self::sortedIds($named) : [],
        ]);

        return $this->result(AssignOutcome::Created, $group, $assignment->id, $named, new Collection);
    }

    /**
     * @param  Collection<int, User>  $added
     * @param  Collection<int, User>  $alreadyHadIt
     */
    private function result(AssignOutcome $outcome, Group $group, int $assignmentId, Collection $added, Collection $alreadyHadIt): AssignResult
    {
        return new AssignResult($outcome, $this->show($group, $assignmentId), $added->values(), $alreadyHadIt->values());
    }

    private function show(Group $group, int $assignmentId): Assignment
    {
        return GroupAssignments::for($group)->whereKey($assignmentId)->sole();
    }

    /**
     * @param  Collection<int, User>  $learners
     * @return list<int>
     */
    private static function sortedIds(Collection $learners): array
    {
        return $learners->pluck('id')->sort()->values()->all();
    }
}
