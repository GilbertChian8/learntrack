<?php

namespace App\Queries\Results;

/**
 * An assignment's numbers and the reason it ranks where it does.
 */
readonly class RankedAssignment
{
    public function __construct(
        public AssignmentStats $stats,
        public string $reason,
    ) {}
}
