<?php

namespace App\Http\Resources;

use App\Actions\Results\ChangeDueDateResult;
use App\Support\InstitutionTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ChangeDueDateResult
 */
class ChangeDueDateResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $institutionTime = new InstitutionTime($this->assignment->group->institution->timezone);

        return [
            'changed' => $this->changed,
            'due_at' => $institutionTime->format($this->assignment->due_at),
            'previous_due_at' => $institutionTime->format($this->previousDueAt),
            'assignment' => new AssignmentResource($this->assignment),
        ];
    }
}
