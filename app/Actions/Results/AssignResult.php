<?php

namespace App\Actions\Results;

use App\Enums\AssignOutcome;
use App\Models\Assignment;
use App\Models\User;
use Illuminate\Support\Collection;

readonly class AssignResult
{
    /**
     * @param  Assignment  $assignment  loaded through GroupAssignments
     * @param  Collection<int, User>  $added
     * @param  Collection<int, User>  $alreadyHadIt
     */
    public function __construct(
        public AssignOutcome $result,
        public Assignment $assignment,
        public Collection $added,
        public Collection $alreadyHadIt,
    ) {}
}
