<?php

namespace App\Mcp\Schemas;

use App\Enums\AssignmentScope;
use App\Enums\ContentType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\ObjectType;

/**
 * The assignment object REST returns (api-contract.md, Shared objects).
 */
class AssignmentSchema
{
    public static function make(JsonSchema $schema): ObjectType
    {
        return $schema->object([
            'id' => $schema->integer()->required(),
            'group_id' => $schema->integer()->required(),
            'content' => $schema->object([
                'id' => $schema->integer()->required(),
                'title' => $schema->string()->required(),
                'topic' => $schema->string()->required(),
                'type' => $schema->string()->enum(ContentType::class)->required(),
                'estimated_minutes' => $schema->integer()->required(),
            ])->withoutAdditionalProperties()->required(),
            'due_at' => $schema->string()->format('date-time')->required(),
            'scope' => $schema->string()->enum(AssignmentScope::class)->required(),
            'targeted_count' => $schema->integer()->min(0)->required(),
            'learners' => $schema->array()->items(LearnerSchema::make($schema))->nullable()->required(),
            'created_at' => $schema->string()->format('date-time')->required(),
        ])->withoutAdditionalProperties();
    }
}
