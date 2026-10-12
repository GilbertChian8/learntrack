<?php

namespace App\Mcp\Schemas;

use App\Queries\Results\StatusCounts;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\ObjectType;

class CountsSchema
{
    public static function make(JsonSchema $schema): ObjectType
    {
        return $schema->object([
            'not_started' => $schema->integer()->min(0)->required(),
            'in_progress' => $schema->integer()->min(0)->required(),
            'completed' => $schema->integer()->min(0)->required(),
            'overdue' => $schema->integer()->min(0)->required(),
        ])->withoutAdditionalProperties();
    }

    /**
     * @return array{not_started: int, in_progress: int, completed: int, overdue: int}
     */
    public static function from(StatusCounts $counts): array
    {
        return [
            'not_started' => $counts->notStarted,
            'in_progress' => $counts->inProgress,
            'completed' => $counts->completed,
            'overdue' => $counts->overdue,
        ];
    }
}
