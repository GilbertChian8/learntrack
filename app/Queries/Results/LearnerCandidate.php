<?php

namespace App\Queries\Results;

/**
 * A learner whose name matched, with the names of the educator's groups they are in.
 */
readonly class LearnerCandidate
{
    /**
     * @param  list<string>  $groupNames
     */
    public function __construct(
        public int $id,
        public string $name,
        public array $groupNames,
    ) {}
}
