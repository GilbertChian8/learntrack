<?php

namespace App\Support;

use App\Exceptions\DueDateException;
use App\Models\Institution;
use Carbon\CarbonImmutable;

/**
 * A due date from REST or MCP: YYYY-MM-DD (23:59:59 that day in the institution's
 * time zone) or an ISO 8601 date-time with an offset. Always in the future, returned in UTC.
 */
class DueDate
{
    private const string DATE = '/^(\d{4})-(\d{2})-(\d{2})$/D';

    private const string DATE_TIME = '/^(\d{4})-(\d{2})-(\d{2})T([01]\d|2[0-3]):[0-5]\d(:[0-5]\d(\.\d+)?)?(Z|[+-]([01]\d|2[0-3]):[0-5]\d)$/D';

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

    private static function read(string $input, string $timezone): ?CarbonImmutable
    {
        if (preg_match(self::DATE, $input, $date) === 1 && checkdate((int) $date[2], (int) $date[3], (int) $date[1])) {
            return CarbonImmutable::parse("{$input} 23:59:59", $timezone)->utc();
        }

        if (preg_match(self::DATE_TIME, $input, $date) === 1 && checkdate((int) $date[2], (int) $date[3], (int) $date[1])) {
            return CarbonImmutable::parse($input)->utc()->startOfSecond();
        }

        return null;
    }
}
