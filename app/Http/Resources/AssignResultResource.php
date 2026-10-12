<?php

namespace App\Http\Resources;

use App\Actions\Results\AssignResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AssignResult
 */
class AssignResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'result' => $this->result->value,
            'assignment' => new AssignmentResource($this->assignment),
            'added' => LearnerResource::collection($this->added),
            'already_had_it' => LearnerResource::collection($this->alreadyHadIt),
        ];
    }
}
