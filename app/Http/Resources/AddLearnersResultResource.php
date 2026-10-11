<?php

namespace App\Http\Resources;

use App\Actions\Results\AddLearnersResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AddLearnersResult
 */
class AddLearnersResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'added' => LearnerResource::collection($this->added),
            'already_members' => LearnerResource::collection($this->alreadyMembers),
        ];
    }
}
