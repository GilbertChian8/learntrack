<?php

namespace App\Http\Requests;

use App\Exceptions\DueDateException;
use App\Models\Institution;
use App\Support\DueDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * A body with due_at. The date is read in the institution's time zone, once the caller knows it.
 */
class DueDateRequest extends FormRequest
{
    public const string NOT_A_DATE = 'The due date must be a date (YYYY-MM-DD) or an ISO 8601 date-time.';

    public const string IN_THE_PAST = 'The due date must be in the future.';

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'due_at' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'due_at.string' => self::NOT_A_DATE,
        ];
    }

    /**
     * @throws ValidationException
     */
    public function dueAt(Institution $institution): CarbonImmutable
    {
        try {
            return DueDate::parse($this->validated('due_at'), $institution);
        } catch (DueDateException $exception) {
            throw ValidationException::withMessages(['due_at' => $exception->isInThePast() ? self::IN_THE_PAST : self::NOT_A_DATE]);
        }
    }
}
