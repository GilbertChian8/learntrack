<?php

namespace App\Support;

use App\Enums\AuditAction;
use App\Enums\AuditChannel;
use App\Models\Assignment;
use App\Models\AuditEntry;
use App\Models\Group;
use App\Models\User;

/**
 * Writes one audit_log row. Actions call it inside their own transaction.
 */
class AuditWriter
{
    /**
     * @param  array<string, mixed>  $changes  ids and values only, never names or emails
     */
    public static function record(User $actor, AuditChannel $channel, AuditAction $action, Group|Assignment $subject, array $changes): AuditEntry
    {
        return AuditEntry::query()->create([
            'actor_id' => $actor->id,
            'channel' => $channel,
            'action' => $action,
            'group_id' => $subject instanceof Group ? $subject->id : $subject->group_id,
            'subject_type' => $subject instanceof Group ? 'group' : 'assignment',
            'subject_id' => $subject->id,
            'changes' => $changes,
        ]);
    }
}
