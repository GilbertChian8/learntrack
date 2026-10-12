<?php

namespace App\Support;

/**
 * A score in a text: a whole number shows no decimals, otherwise one (42.5, 40).
 */
class Score
{
    public static function format(float $score): string
    {
        return $score === floor($score) ? (string) (int) $score : number_format($score, 1, '.', '');
    }
}
