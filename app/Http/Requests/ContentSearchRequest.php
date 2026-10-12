<?php

namespace App\Http\Requests;

class ContentSearchRequest extends SearchRequest
{
    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'topic' => ['nullable', 'string'],
        ];
    }

    public function topic(): ?string
    {
        return $this->validated('topic');
    }
}
