<?php

namespace App\Support;

use App\Exceptions\DueDateException;
use App\Models\Institution;
use Carbon\CarbonImmutable;

/**
 * A due date from REST or MCP: YYYY-MM-DD (23:59:59 that day in the institution's
 * time zone) or an RFC 3339 date-time with an offset, its fraction of a second dropped.
 * Always in the future, returned in UTC.
 */
class DueDate
{
    private const string DATE = '/^(\d{4})-(\d{2})-(\d{2})$/D';

    private const string DATE_TIME = '/^(\d{4})-(\d{2})-(\d{2})T([01]\d|2[0-3]):[0-5]\d:[0-5]\d(\.\d+)?(Z|[+-]([01]\d|2[0-3]):[0-5]\d)$/D';

    /**
     * @throws DueDateException
     */
    public static function parse(string $input, Institution $institution): CarbonImmutable
    {
        $dueAt = self::read($input, $institution->timezone) ?? throw DueDateException::notADate($input);
        $now = CarbonImmutable::now();

        if ($dueAt->lessThanOrEqualTo($now)) {
            throw DueDateException::inThePast($now->setTimezone($institution->timezone));
        }

        return $dueAt;
    }

    /**
     * A moment after 9999 in UTC does not fit a DATETIME column, so it is not a date either.
     */
    private static function read(string $input, string $timezone): ?CarbonImmutable
    {
        $dueAt = match (true) {
            self::matches(self::DATE, $input) => CarbonImmutable::parse("{$input} 23:59:59", $timezone)->utc(),
            self::matches(self::DATE_TIME, $input) => CarbonImmutable::parse($input)->utc()->startOfSecond(),
            default => null,
        };

        return $dueAt !== null && $dueAt->year <= 9999 ? $dueAt : null;
    }

    private static function matches(string $pattern, string $input): bool
    {
        return preg_match($pattern, $input, $date) === 1 && checkdate((int) $date[2], (int) $date[3], (int) $date[1]);
    }
}
