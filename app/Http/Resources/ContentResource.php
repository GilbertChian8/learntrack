<?php

namespace App\Http\Resources;

use App\Models\ContentItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ContentItem
 */
class ContentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'topic' => $this->topic,
            'type' => $this->type->value,
            'estimated_minutes' => $this->estimated_minutes,
        ];
    }
}
