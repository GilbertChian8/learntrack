<?php

namespace App\Actions\Results;

use stdClass;

readonly class RecordProgressResult
{
    /**
     * @param  stdClass  $row  the assignment's LearnerAssignments row, read after the write
     */
    public function __construct(
        public bool $changed,
        public stdClass $row,
    ) {}
}
