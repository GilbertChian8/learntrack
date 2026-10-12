<?php

namespace App\Queries\Results;

readonly class HardestAssignmentsResult
{
    /**
     * @param  list<RankedAssignment>  $assignments
     */
    public function __construct(
        public int $groupId,
        public string $groupName,
        public array $assignments,
    ) {}
}
