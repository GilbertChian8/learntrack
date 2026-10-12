<?php

namespace App\GraphQL\Scalars;

use Carbon\CarbonImmutable;
use GraphQL\Error\Error;
use GraphQL\Error\SerializationError;
use GraphQL\Language\AST\Node;
use GraphQL\Type\Definition\ScalarType;

/**
 * ISO 8601 with the institution's offset. Query results already hold their date-times
 * in the institution's time zone (InstitutionTime). Output only.
 */
class DateTime extends ScalarType
{
    public function serialize(mixed $value): string
    {
        if (! $value instanceof CarbonImmutable) {
            throw new SerializationError('DateTime can only serialize a CarbonImmutable.');
        }

        return $value->toIso8601String();
    }

    public function parseValue(mixed $value): never
    {
        throw new Error('DateTime is not accepted as input.');
    }

    public function parseLiteral(Node $valueNode, ?array $variables = null): never
    {
        throw new Error('DateTime is not accepted as input.');
    }
}
