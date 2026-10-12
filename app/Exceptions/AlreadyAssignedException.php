<?php

namespace App\Exceptions;

use App\Http\Resources\AssignmentResource;
use App\Models\Assignment;
use App\Support\InstitutionTime;

/**
 * The content is already assigned to the group with another due date. Nothing changed.
 */
class AlreadyAssignedException extends ConflictException
{
    /**
     * @param  Assignment  $assignment  the existing one, loaded through GroupAssignments
     */
    public function __construct(public readonly Assignment $assignment)
    {
        $dueAt = (new InstitutionTime($assignment->group->institution->timezone))->format($assignment->due_at);

        parent::__construct("{$assignment->contentItem->title} is already assigned to {$assignment->group->name}, due {$dueAt}. Change the due date with PATCH /api/v1/assignments/{$assignment->id}.");
    }

    public function code(): string
    {
        return 'already_assigned';
    }

    /**
     * @return array{assignment: array<string, mixed>}
     */
    public function details(): array
    {
        return ['assignment' => (new AssignmentResource($this->assignment))->resolve()];
    }
}
