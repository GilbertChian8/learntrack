<?php

namespace App\Actions\Results;

use App\Enums\AssignOutcome;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What AssignContent decided inside its transaction. A null outcome means the
 * group already has this content with another due date.
 */
final readonly class AssignDecision
{
    /**
     * @param  Collection<int, User>  $added
     * @param  Collection<int, User>  $alreadyHadIt
     */
    private function __construct(
        public ?AssignOutcome $outcome,
        public int $assignmentId,
        public Collection $added,
        public Collection $alreadyHadIt,
    ) {}

    /**
     * @param  Collection<int, User>  $added
     * @param  Collection<int, User>  $alreadyHadIt
     */
    public static function made(AssignOutcome $outcome, int $assignmentId, Collection $added, Collection $alreadyHadIt): self
    {
        return new self($outcome, $assignmentId, $added->values(), $alreadyHadIt->values());
    }

    public static function alreadyAssigned(int $assignmentId): self
    {
        return new self(null, $assignmentId, new Collection, new Collection);
    }
}
