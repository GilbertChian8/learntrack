<?php

namespace App\GraphQL;

use BackedEnum;
use GraphQL\Type\Definition\EnumType;

/**
 * A GraphQL enum over a PHP backed enum, named in upper case: DerivedStatus::NotStarted
 * ('not_started') is NOT_STARTED, and a resolver returns the PHP case.
 */
class BackedEnumType extends EnumType
{
    /**
     * @param  class-string<BackedEnum>  $enum
     */
    public function __construct(string $name, string $enum)
    {
        $values = [];

        foreach ($enum::cases() as $case) {
            $values[strtoupper($case->value)] = ['value' => $case];
        }

        parent::__construct(['name' => $name, 'values' => $values]);
    }
}
