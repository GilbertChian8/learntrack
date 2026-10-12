<?php

namespace App\Queries\Results;

readonly class LearnerSummaryResult
{
    /**
     * @param  list<LearnerGroupProgress>  $groups
     */
    public function __construct(
        public int $learnerId,
        public string $learnerName,
        public array $groups,
    ) {}
}
