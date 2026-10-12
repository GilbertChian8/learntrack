<?php

namespace App\Http\Requests;

use App\Exceptions\NotFoundException;
use App\Models\ContentItem;

class AssignContentRequest extends DueDateRequest
{
    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'content_item_id' => ['required', 'integer:strict'],
            'learner_ids' => ['sometimes', 'list', 'min:1', 'max:60'],
            'learner_ids.*' => ['integer:strict', 'distinct'],
        ];
    }

    /**
     * The content library is the same for every educator, so only a missing item is not found.
     */
    public function contentItem(): ContentItem
    {
        return ContentItem::query()->findOr($this->validated('content_item_id'), fn () => throw NotFoundException::contentItem());
    }

    /**
     * @return list<int>
     */
    public function learnerIds(): array
    {
        return $this->validated('learner_ids', []);
    }
}
