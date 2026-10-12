<?php

namespace App\Http\Resources;

use App\Support\InstitutionTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of GET /api/v1/me/assignments, from a LearnerAssignments row.
 *
 * @property-read int $assignment_id
 * @property-read int $group_id
 * @property-read string $group_name
 * @property-read int $content_item_id
 * @property-read string $title
 * @property-read string $topic
 * @property-read string $type
 * @property-read int $estimated_minutes
 * @property-read string $due_at
 * @property-read string $status
 * @property-read int|null $score
 * @property-read string|null $started_at
 * @property-read string|null $completed_at
 * @property-read string $timezone
 */
class LearnerAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $institutionTime = new InstitutionTime($this->timezone);
        $format = fn (?string $utc): ?string => $institutionTime->format($institutionTime->fromUtc($utc));

        return [
            'assignment_id' => $this->assignment_id,
            'group' => [
                'id' => $this->group_id,
                'name' => $this->group_name,
            ],
            'content' => [
                'id' => $this->content_item_id,
                'title' => $this->title,
                'topic' => $this->topic,
                'type' => $this->type,
                'estimated_minutes' => $this->estimated_minutes,
            ],
            'due_at' => $format($this->due_at),
            'status' => $this->status,
            'score' => $this->score,
            'started_at' => $format($this->started_at),
            'completed_at' => $format($this->completed_at),
        ];
    }
}
