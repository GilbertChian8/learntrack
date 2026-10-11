<?php

namespace App\Queries\Results;

use App\Enums\ContentType;
use App\Enums\DerivedStatus;
use Carbon\CarbonImmutable;

/**
 * One assignment that targets the learner, with the learner's status on it.
 */
readonly class AssignmentStatus
{
    public function __construct(
        public int $assignmentId,
        public string $title,
        public ContentType $type,
        public CarbonImmutable $dueAt,
        public DerivedStatus $status,
        public ?int $score,
        public ?CarbonImmutable $completedAt,
    ) {}
}
