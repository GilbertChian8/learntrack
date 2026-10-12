<?php

use App\Exceptions\DueDateException;
use App\Models\Institution;
use App\Support\DueDate;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-09 08:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

$berlin = fn (): Institution => new Institution(['timezone' => 'Europe/Berlin']);

$parseError = function (string $input) use ($berlin): DueDateException {
    try {
        DueDate::parse($input, $berlin());
    } catch (DueDateException $exception) {
        return $exception;
    }

    throw new RuntimeException("{$input} parsed.");
};

test('a bare date is 23:59:59 that day in the institution\'s time zone, in UTC', function () use ($berlin) {
    $dueAt = DueDate::parse('2026-10-16', $berlin());

    expect($dueAt->toIso8601ZuluString())->toBe('2026-10-16T21:59:59Z')
        ->and($dueAt->timezoneName)->toBe('UTC')
        ->and(DueDate::parse('2026-12-16', $berlin())->toIso8601ZuluString())->toBe('2026-12-16T22:59:59Z');
});

test('an ISO 8601 date-time with an offset keeps its instant, in UTC', function (string $input, string $utc) use ($berlin) {
    expect(DueDate::parse($input, $berlin())->toIso8601ZuluString())->toBe($utc);
})->with([
    'with an offset' => ['2026-10-16T18:00:00+02:00', '2026-10-16T16:00:00Z'],
    'in UTC' => ['2026-10-16T16:00:00Z', '2026-10-16T16:00:00Z'],
    'without seconds' => ['2026-10-16T18:00-05:00', '2026-10-16T23:00:00Z'],
    'with a fraction, cut to the second' => ['2026-10-16T18:00:00.750+02:00', '2026-10-16T16:00:00Z'],
]);

test('anything else is not a date and carries the input', function (string $input) use ($parseError) {
    $exception = $parseError($input);

    expect($exception->isInThePast())->toBeFalse()
        ->and($exception->input)->toBe($input)
        ->and($exception->today)->toBeNull();
})->with([
    'words' => 'next friday',
    'empty' => '',
    'a day that does not exist' => '2026-02-30',
    'a date-time without an offset' => '2026-10-16T18:00:00',
    'a time zone name instead of an offset' => '2026-10-16T18:00:00Europe/Berlin',
    'an hour out of range' => '2026-10-16T24:00:00Z',
    'a trailing newline' => "2026-10-16\n",
    'another date order' => '16-10-2026',
]);

test('a moment not in the future names today in the institution\'s time zone', function (string $input) use ($parseError) {
    CarbonImmutable::setTestNow('2026-10-09 22:30:00');

    $exception = $parseError($input);

    expect($exception->isInThePast())->toBeTrue()
        ->and($exception->input)->toBeNull()
        ->and($exception->today?->toDateString())->toBe('2026-10-10')
        ->and($exception->today?->timezoneName)->toBe('Europe/Berlin');
})->with([
    'yesterday in Berlin' => '2026-10-09',
    'an hour ago' => '2026-10-09T21:30:00Z',
    'now' => '2026-10-09T22:30:00Z',
]);

test('later today in the institution\'s time zone is in the future', function () use ($berlin) {
    expect(DueDate::parse('2026-10-09', $berlin())->toIso8601ZuluString())->toBe('2026-10-09T21:59:59Z');
});
