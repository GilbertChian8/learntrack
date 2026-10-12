<?php

namespace App\Http\Requests;

use App\Enums\ProgressStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The shape of the body. The score rules per status and content type are in RecordProgress.
 */
class RecordProgressRequest extends FormRequest
{
    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(ProgressStatus::class)],
            'score' => ['nullable', 'integer:strict', 'between:0,100'],
        ];
    }

    public function status(): ProgressStatus
    {
        return ProgressStatus::from($this->validated('status'));
    }

    public function score(): ?int
    {
        return $this->validated('score');
    }
}
