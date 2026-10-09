<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * Missing and out of scope look the same (ADR-006). Each interface adds its own wording.
 */
class NotFoundException extends RuntimeException implements ShouldntReport
{
    private function __construct(public readonly string $subject)
    {
        parent::__construct();
    }

    public static function group(): self
    {
        return new self('Group');
    }

    public static function learner(): self
    {
        return new self('Learner');
    }

    public static function assignment(): self
    {
        return new self('Assignment');
    }

    public static function contentItem(): self
    {
        return new self('Content item');
    }
}
