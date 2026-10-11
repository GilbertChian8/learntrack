<?php

namespace App\Http\Requests;

/**
 * A list with ?search.
 */
class SearchRequest extends ListRequest
{
    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'search' => ['nullable', 'string'],
        ];
    }

    public function search(): ?string
    {
        return $this->validated('search');
    }
}
