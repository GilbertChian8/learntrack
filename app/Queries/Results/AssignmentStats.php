<?php

namespace App\Queries\Results;

use App\Enums\AssignmentScope;
use App\Enums\ContentType;
use Carbon\CarbonImmutable;

readonly class AssignmentStats
{
    public function __construct(
        public int $id,
        public string $title,
        public string $topic,
        public ContentType $type,
        public CarbonImmutable $dueAt,
        public AssignmentScope $scope,
        public int $targetedCount,
        public int $completedCount,
        public float $completionRate,
        public ?float $averageScore,
    ) {}
}
