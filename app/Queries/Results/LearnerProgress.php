<?php

namespace App\Queries\Results;

/**
 * One learner in one group.
 */
readonly class LearnerProgress
{
    /**
     * @param  list<Reason>  $reasons
     * @param  list<AssignmentStatus>  $statuses
     */
    public function __construct(
        public int $learnerId,
        public string $learnerName,
        public bool $behind,
        public array $reasons,
        public ?float $averageScore,
        public StatusCounts $counts,
        public array $statuses,
    ) {}
}
