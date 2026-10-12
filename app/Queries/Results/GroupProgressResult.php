<?php

namespace App\Queries\Results;

use Carbon\CarbonImmutable;

readonly class GroupProgressResult
{
    /**
     * @param  list<LearnerProgress>  $learners
     * @param  list<AssignmentStats>  $assignments
     */
    public function __construct(
        public int $groupId,
        public string $groupName,
        public string $institutionName,
        public CarbonImmutable $generatedAt,
        public int $learnerCount,
        public int $behindCount,
        public array $learners,
        public array $assignments,
    ) {}
}
