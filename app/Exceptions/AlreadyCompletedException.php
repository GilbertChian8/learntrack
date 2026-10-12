<?php

namespace App\Exceptions;

use App\Support\InstitutionTime;
use stdClass;

/**
 * A completed assignment cannot move back or change its score. Nothing changed.
 */
class AlreadyCompletedException extends ConflictException
{
    /**
     * @param  stdClass  $row  the assignment's LearnerAssignments row
     */
    public function __construct(public readonly stdClass $row)
    {
        parent::__construct($row->score === null
            ? 'This assignment is already completed.'
            : "This assignment is already completed with a score of {$row->score}.");
    }

    public function code(): string
    {
        return 'already_completed';
    }

    /**
     * @return array{status: string, score: int|null, completed_at: string}
     */
    public function details(): array
    {
        $institutionTime = new InstitutionTime($this->row->timezone);

        return [
            'status' => $this->row->progress_status,
            'score' => $this->row->score,
            'completed_at' => $institutionTime->format($institutionTime->fromUtc($this->row->completed_at)),
        ];
    }
}
