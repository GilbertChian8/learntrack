<?php

namespace App\Http\Resources;

use App\Actions\Results\RecordProgressResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * The fields come from the same row and formatting as GET /api/v1/me/assignments.
 *
 * @mixin RecordProgressResult
 */
class ProgressResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $assignment = (new LearnerAssignmentResource($this->row))->resolve($request);

        return [
            ...Arr::only($assignment, ['assignment_id', 'status', 'score', 'started_at', 'completed_at', 'due_at']),
            'changed' => $this->changed,
        ];
    }
}
