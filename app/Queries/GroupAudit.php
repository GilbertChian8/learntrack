<?php

namespace App\Queries;

use App\Models\AuditEntry;
use App\Models\Group;
use Illuminate\Database\Eloquent\Builder;

/**
 * A group's audit rows, newest first, with each actor's name and the
 * institution's time zone. The actors load in one query. The cursor pages on
 * created_at_key, the raw value: a date cast would drop the microseconds.
 */
class GroupAudit
{
    /**
     * @return Builder<AuditEntry>
     */
    public static function for(Group $group): Builder
    {
        return AuditEntry::query()
            ->join('learner_groups', 'learner_groups.id', '=', 'audit_log.group_id')
            ->join('institutions', 'institutions.id', '=', 'learner_groups.institution_id')
            ->where('audit_log.group_id', $group->id)
            ->select('audit_log.*', 'audit_log.created_at as created_at_key', 'institutions.timezone as institution_timezone')
            ->with('actor:id,name')
            ->orderByDesc('created_at_key')
            ->orderByDesc('audit_log.id');
    }
}
