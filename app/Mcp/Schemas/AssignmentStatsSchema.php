<?php

namespace App\Mcp\Schemas;

use App\Enums\AssignmentScope;
use App\Enums\ContentType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\ObjectType;

class AssignmentStatsSchema
{
    public static function make(JsonSchema $schema): ObjectType
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
        ])->withoutAdditionalProperties();
    }
}
