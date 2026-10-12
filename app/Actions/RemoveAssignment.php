<?php

namespace App\Actions;

use App\Enums\AuditAction;
use App\Enums\AuditChannel;
use App\Models\Assignment;
use App\Models\User;
use App\Support\AuditWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Soft delete: removed_at and removed_key (the row's own id) in one write. Progress rows stay.
 */
class RemoveAssignment
{
    /**
     * @return bool whether the assignment was active until now
     */
    public function handle(User $educator, Assignment $assignment, AuditChannel $channel): bool
    {
        return DB::transaction(function () use ($educator, $assignment, $channel): bool {
            $removedAt = CarbonImmutable::now()->startOfSecond();

            $removed = Assignment::query()
                ->active()
                ->whereKey($assignment->id)
                ->update(['removed_at' => $removedAt, 'removed_key' => $assignment->id]);

            if ($removed === 0) {
                return false;
            }

            AuditWriter::record($educator, $channel, AuditAction::AssignmentRemoved, $assignment, ['removed_at' => $removedAt->toIso8601ZuluString()]);

            return true;
        });
    }
}
