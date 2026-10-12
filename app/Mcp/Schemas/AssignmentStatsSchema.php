<?php

namespace App\Mcp\Schemas;

use App\Enums\AssignmentScope;
use App\Enums\ContentType;
use App\Queries\Results\AssignmentStats;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\ObjectType;
use Illuminate\JsonSchema\Types\Type;

class AssignmentStatsSchema
{
    /**
     * @param  array<string, Type>  $with  more fields, for a tool that adds to the object
     */
    public static function make(JsonSchema $schema, array $with = []): ObjectType
    {
        return $schema->object([
            'id' => $schema->integer()->required(),
            'title' => $schema->string()->required(),
            'topic' => $schema->string()->required(),
            'type' => $schema->string()->enum(ContentType::class)->required(),
            'due_at' => $schema->string()->format('date-time')->required(),
            'scope' => $schema->string()->enum(AssignmentScope::class)->required(),
            'targeted_count' => $schema->integer()->min(0)->required(),
            'completed_count' => $schema->integer()->min(0)->required(),
            'completion_rate' => $schema->number()->min(0)->max(1)->required(),
            'average_score' => $schema->number()->nullable()->required(),
            ...$with,
        ])->withoutAdditionalProperties();
    }

    /**
     * @return array<string, mixed>
     */
    public static function from(AssignmentStats $assignment): array
    {
        return [
            'id' => $assignment->id,
            'title' => $assignment->title,
            'topic' => $assignment->topic,
            'type' => $assignment->type->value,
            'due_at' => $assignment->dueAt->toIso8601String(),
            'scope' => $assignment->scope->value,
            'targeted_count' => $assignment->targetedCount,
            'completed_count' => $assignment->completedCount,
            'completion_rate' => $assignment->completionRate,
            'average_score' => $assignment->averageScore,
        ];
    }
}
