<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * Missing and out of scope look the same (ADR-006). Every interface prints the message as is.
 */
class NotFoundException extends RuntimeException implements ShouldntReport
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function group(): self
    {
        return new self('Group not found.');
    }

    public static function learner(): self
    {
        return new self('Learner not found.');
    }

    public static function assignment(): self
    {
        return new self('Assignment not found.');
    }

    public static function contentItem(): self
    {
        return new self('Content item not found.');
    }
}
