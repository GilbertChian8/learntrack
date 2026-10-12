<?php

namespace App\Mcp\Schemas;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\ObjectType;

class LearnerSchema
{
    public static function make(JsonSchema $schema): ObjectType
    {
        return $schema->object([
            'id' => $schema->integer()->required(),
            'name' => $schema->string()->required(),
        ])->withoutAdditionalProperties();
    }
}
