<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddLearnersRequest extends FormRequest
{
    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'user_ids' => ['required', 'list', 'min:1', 'max:200'],
            'user_ids.*' => ['integer:strict', 'distinct'],
        ];
    }

    /**
     * @return list<int>
     */
    public function userIds(): array
    {
        return $this->validated('user_ids');
    }
}
