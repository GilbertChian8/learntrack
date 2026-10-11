<?php

namespace App\Http\Requests;

/**
 * A list with ?search, matched anywhere in the string, case-insensitive.
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

    /**
     * A LIKE pattern for the search, with % and _ taken literally.
     */
    public function searchPattern(): ?string
    {
        $search = $this->validated('search');

        return $search === null ? null : '%'.addcslashes($search, '\\%_').'%';
    }
}
