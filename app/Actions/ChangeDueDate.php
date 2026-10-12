<?php

namespace App\Actions;

use App\Actions\Results\ChangeDueDateResult;
use App\Enums\AuditAction;
use App\Enums\AuditChannel;
use App\Exceptions\NotFoundException;
use App\Models\Assignment;
use App\Models\User;
use App\Queries\GroupAssignments;
use App\Support\AuditWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ChangeDueDate
{
    /**
     * The caller found the assignment through Scope::assignment.
     *
     * @throws NotFoundException when it was removed in the meantime
     */
    public function handle(User $educator, Assignment $assignment, CarbonImmutable $dueAt, AuditChannel $channel): ChangeDueDateResult
    {
        return DB::transaction(function () use ($educator, $assignment, $dueAt, $channel): ChangeDueDateResult {
            $locked = Assignment::query()->active()->whereKey($assignment->id)->lockForUpdate()->first()
                ?? throw NotFoundException::assignment();
            $previousDueAt = $locked->due_at;
            $changed = ! $previousDueAt->equalTo($dueAt);

            if ($changed) {
                $locked->update(['due_at' => $dueAt->utc()]);

                AuditWriter::record($educator, $channel, AuditAction::AssignmentDueChanged, $locked, ['due_at' => [
                    'from' => $previousDueAt->toIso8601ZuluString(),
                    'to' => $dueAt->toIso8601ZuluString(),
                ]]);
            }

            return new ChangeDueDateResult(
                changed: $changed,
                previousDueAt: $previousDueAt,
                assignment: GroupAssignments::for($assignment->group)->whereKey($assignment->id)->sole(),
            );
        });
    }
}
