<?php

namespace App\Actions;

use App\Actions\Results\RecordProgressResult;
use App\Enums\ContentType;
use App\Enums\ProgressStatus;
use App\Exceptions\AlreadyCompletedException;
use App\Exceptions\NotFoundException;
use App\Models\Assignment;
use App\Models\Progress;
use App\Models\User;
use App\Policies\AssignmentPolicy;
use App\Queries\LearnerAssignments;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The RecordProgress rules of the TDD: forward only, completed is final.
 * Progress has no audit row; the audit log records educator actions.
 */
class RecordProgress
{
    /**
     * The caller found the assignment through Scope::learnerAssignment. The answer is read
     * after the commit, so it shows writes that other requests committed while this one waited.
     *
     * @throws NotFoundException when it stopped targeting the learner in the meantime
     * @throws ValidationException
     * @throws AlreadyCompletedException
     */
    public function handle(User $learner, Assignment $assignment, ProgressStatus $status, ?int $score): RecordProgressResult
    {
        $record = fn (): ?bool => DB::transaction(fn (): ?bool => $this->apply($learner, $assignment, $status, $score));

        try {
            $changed = $record();
        } catch (UniqueConstraintViolationException) {
            $changed = $record();
        }

        $row = LearnerAssignments::for($learner)->where('assignment_id', $assignment->id)->first()
            ?? throw NotFoundException::assignment();

        if ($changed === null) {
            throw new AlreadyCompletedException($row);
        }

        return new RecordProgressResult($changed, $row);
    }

    /**
     * @return bool|null whether the progress changed; null when a completed row refuses the request
     *
     * @throws NotFoundException
     * @throws ValidationException
     */
    private function apply(User $learner, Assignment $assignment, ProgressStatus $status, ?int $score): ?bool
    {
        $targeting = AssignmentPolicy::targeting($learner)->whereKey($assignment->id)->with('contentItem')->sharedLock()->first()
            ?? throw NotFoundException::assignment();

        $this->ensureScoreFits($targeting->contentItem->type, $status, $score);

        $progress = $this->lockProgress($learner, $assignment);
        $now = CarbonImmutable::now()->startOfSecond();

        if ($progress === null) {
            Progress::query()->create([
                'assignment_id' => $assignment->id,
                'user_id' => $learner->id,
                'status' => $status,
                'score' => $score,
                'started_at' => $now,
                'completed_at' => $status === ProgressStatus::Completed ? $now : null,
            ]);

            return true;
        }

        if ($progress->status === ProgressStatus::Completed) {
            $isSameState = $status === ProgressStatus::Completed && $progress->score === $score;

            return $isSameState ? false : null;
        }

        if ($status === ProgressStatus::InProgress) {
            return false;
        }

        $progress->update(['status' => $status, 'score' => $score, 'completed_at' => $now]);

        return true;
    }

    /**
     * @throws ValidationException
     */
    private function ensureScoreFits(ContentType $type, ProgressStatus $status, ?int $score): void
    {
        $message = match (true) {
            $status === ProgressStatus::InProgress && $score !== null => 'A score is only accepted with the status completed.',
            $type === ContentType::QuestionSet && $score === null && $status === ProgressStatus::Completed => 'A completed question set needs a score.',
            $type === ContentType::Article && $score !== null => 'An article does not take a score.',
            default => null,
        };

        if ($message !== null) {
            throw ValidationException::withMessages(['score' => $message]);
        }
    }

    /**
     * A plain read first: a locking read of a missing row takes a gap lock, and two
     * first writes would then deadlock instead of hitting the unique key.
     */
    private function lockProgress(User $learner, Assignment $assignment): ?Progress
    {
        $id = Progress::query()
            ->where('assignment_id', $assignment->id)
            ->where('user_id', $learner->id)
            ->value('id');

        return $id === null ? null : Progress::query()->whereKey($id)->lockForUpdate()->first();
    }
}
