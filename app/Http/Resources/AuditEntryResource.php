<?php

namespace App\Http\Resources;

use App\Models\AuditEntry;
use App\Support\InstitutionTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The audit_entry object, from a GroupAudit row. changes are returned as stored.
 *
 * @mixin AuditEntry
 *
 * @property-read string $institution_timezone
 */
class AuditEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'actor' => new LearnerResource($this->actor),
            'channel' => $this->channel->value,
            'action' => $this->action->value,
            'subject' => [
                'type' => $this->subject_type,
                'id' => $this->subject_id,
            ],
            'changes' => $this->changes,
            'created_at' => (new InstitutionTime($this->institution_timezone))->format($this->created_at),
        ];
    }
}
