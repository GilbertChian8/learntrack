<?php

namespace App\Mcp\Tools\Concerns;

use App\Exceptions\DueDateException;
use App\Models\Institution;
use App\Support\DueDate;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * A due_date argument, with mcp-tools.md's two date messages as the tool error.
 */
trait ParsesDueDates
{
    /**
     * @throws ValidationException
     */
    protected function dueAt(string $dueDate, Institution $institution): CarbonImmutable
    {
        try {
            return DueDate::parse($dueDate, $institution);
        } catch (DueDateException $exception) {
            throw ValidationException::withMessages(['due_date' => $exception->isInThePast()
                ? "The due date must be in the future. Today is {$exception->today?->toDateString()} in {$institution->timezone}."
                : "The due date \"{$dueDate}\" is not a date. Use YYYY-MM-DD or an ISO 8601 date-time.",
            ]);
        }
    }
}
