<?php

use App\Support\Score;

test('a whole score shows no decimals, any other score one', function (float $score, string $text) {
    expect(Score::format($score))->toBe($text);
})->with([
    [40.0, '40'],
    [0.0, '0'],
    [100.0, '100'],
    [42.5, '42.5'],
    [67.5, '67.5'],
]);
