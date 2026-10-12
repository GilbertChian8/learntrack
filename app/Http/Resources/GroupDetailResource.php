<?php

namespace App\Http\Resources;

use App\Models\Group;
use Illuminate\Http\Request;

/**
 * The group summary plus its educators and learners.
 *
 * @mixin Group
 */
class GroupDetailResource extends GroupResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'educators' => LearnerResource::collection($this->educators),
            'learners' => LearnerResource::collection($this->learners),
        ];
    }
}
