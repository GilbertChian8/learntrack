<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;

/**
 * Stored date-times are UTC; answers show them with the institution's offset.
 */
class InstitutionTime
{
    private readonly DateTimeZone $timezone;

    /**
     * @var array<string, CarbonImmutable>
     */
    private array $converted = [];

    public function __construct(string $timezone)
    {
        $this->timezone = new DateTimeZone($timezone);
    }

    /**
     * A stored UTC value in the institution's time zone. Each value is converted once.
     *
     * @return ($utc is null ? null : CarbonImmutable)
     */
    public function fromUtc(?string $utc): ?CarbonImmutable
    {
        if ($utc === null) {
            return null;
        }

        return $this->converted[$utc] ??= (new CarbonImmutable($utc, 'UTC'))->setTimezone($this->timezone);
    }

    public function in(CarbonInterface $moment): CarbonImmutable
    {
        return $moment->toImmutable()->setTimezone($this->timezone);
    }

    /**
     * ISO 8601 with the institution's offset, for example 2026-10-16T23:59:59+02:00.
     *
     * @return ($moment is null ? null : string)
     */
    public function format(?CarbonInterface $moment): ?string
    {
        return $moment === null ? null : $this->in($moment)->toIso8601String();
    }
}
