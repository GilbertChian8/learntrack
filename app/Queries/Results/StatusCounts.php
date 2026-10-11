<?php

namespace App\Queries\Results;

readonly class StatusCounts
{
    public function __construct(
        public int $notStarted,
        public int $inProgress,
        public int $completed,
        public int $overdue,
    ) {}
}
