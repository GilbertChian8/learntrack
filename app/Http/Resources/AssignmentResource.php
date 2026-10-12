<?php

namespace App\Http\Resources;

use App\Enums\AssignmentScope;
use App\Models\Assignment;
use App\Support\InstitutionTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The assignment object, from a GroupAssignments row.
 *
 * @mixin Assignment
 *
 * @property-read int $targeted_count
 */
class AssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $institutionTime = new InstitutionTime($this->group->institution->timezone);

        return [
            'id' => $this->id,
            'group_id' => $this->group_id,
            'content' => new ContentResource($this->contentItem),
            'due_at' => $institutionTime->format($this->due_at),
            'scope' => $this->scope->value,
            'targeted_count' => $this->targeted_count,
            'learners' => $this->scope === AssignmentScope::Learners ? LearnerResource::collection($this->learners) : null,
            'created_at' => $institutionTime->format($this->created_at),
        ];
    }
}
