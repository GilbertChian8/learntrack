<?php

namespace App\Exceptions;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Carries the case and its input, not a sentence: REST and MCP each write their own message.
 */
class DueDateException extends InvalidArgumentException
{
    private function __construct(
        public readonly ?string $input,
        public readonly ?CarbonImmutable $today,
    ) {
        parent::__construct();
    }

    public static function notADate(string $input): self
    {
        return new self($input, null);
    }

    /**
     * @param  CarbonImmutable  $today  now, in the institution's time zone
     */
    public static function inThePast(CarbonImmutable $today): self
    {
        return new self(null, $today);
    }

    public function isInThePast(): bool
    {
        return $this->today !== null;
    }
}
