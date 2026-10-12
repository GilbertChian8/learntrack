<?php

namespace App\Mcp\Schemas;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\AnyOfType;

/**
 * The two reason objects of api-contract.md section 6.
 */
class ReasonSchema
{
    public static function make(JsonSchema $schema): AnyOfType
    {
        return $schema->anyOf([
            $schema->object([
                'kind' => $schema->string()->enum(['overdue'])->required(),
                'assignment_id' => $schema->integer()->required(),
                'title' => $schema->string()->required(),
                'due_at' => $schema->string()->format('date-time')->required(),
                'days_overdue' => $schema->integer()->min(0)->required(),
                'text' => $schema->string()->required(),
            ])->withoutAdditionalProperties(),
            $schema->object([
                'kind' => $schema->string()->enum(['low_average'])->required(),
                'average_score' => $schema->number()->required(),
                'threshold' => $schema->integer()->required(),
                'scores' => $schema->array()->items($schema->object([
                    'assignment_id' => $schema->integer()->required(),
                    'title' => $schema->string()->required(),
                    'score' => $schema->integer()->required(),
                ])->withoutAdditionalProperties())->required(),
                'text' => $schema->string()->required(),
            ])->withoutAdditionalProperties(),
        ]);
    }
}
