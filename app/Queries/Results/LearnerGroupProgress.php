<?php

namespace App\Queries\Results;

/**
 * One learner in one of the educator's groups.
 */
readonly class LearnerGroupProgress
{
    public function __construct(
        public int $groupId,
        public string $groupName,
        public LearnerProgress $progress,
    ) {}
}
