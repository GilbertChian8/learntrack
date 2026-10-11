<?php

namespace App\Support;

use Carbon\CarbonInterface;
use DateTimeZone;

/**
 * Renders a stored UTC moment as ISO 8601 with the institution's offset.
 */
class InstitutionTime
{
    /**
     * @return ($moment is null ? null : string)
     */
    public static function format(?CarbonInterface $moment, DateTimeZone|string $timezone): ?string
    {
        return $moment?->toImmutable()->setTimezone($timezone)->toIso8601String();
    }
}
