<?php

namespace App\Mcp\Schemas;

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
}
