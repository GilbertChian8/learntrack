<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

abstract class ConflictException extends RuntimeException implements ShouldntReport
{
    abstract public function code(): string;

    /**
     * @return array<string, mixed>
     */
    abstract public function details(): array;
}
