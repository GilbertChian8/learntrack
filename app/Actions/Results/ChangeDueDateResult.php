<?php

namespace App\Actions\Results;

use App\Models\Assignment;
use Carbon\CarbonImmutable;

readonly class ChangeDueDateResult
{
    /**
     * @param  Assignment  $assignment  loaded through GroupAssignments, with the new due_at
     */
    public function __construct(
        public bool $changed,
        public CarbonImmutable $previousDueAt,
        public Assignment $assignment,
    ) {}
}
