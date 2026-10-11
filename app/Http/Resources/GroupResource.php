<?php

namespace App\Http\Resources;

use App\Models\Group;
use App\Support\InstitutionTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The group summary, from a MyGroups row: it carries the institution and the two counts.
 *
 * @mixin Group
 *
 * @property-read int $learner_count
 * @property-read int $assignment_count
 */
class GroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'institution' => [
                'id' => $this->institution->id,
                'name' => $this->institution->name,
            ],
            'learner_count' => $this->learner_count,
            'assignment_count' => $this->assignment_count,
            'created_at' => InstitutionTime::format($this->created_at, $this->institution->timezone),
        ];
    }
}
