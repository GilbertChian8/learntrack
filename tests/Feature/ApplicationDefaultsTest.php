<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Monolog\Formatter\JsonFormatter;

test('models are strict outside production', function () {
    expect(Model::preventsLazyLoading())->toBeTrue()
        ->and(Model::preventsSilentlyDiscardingAttributes())->toBeTrue()
        ->and(Model::preventsAccessingMissingAttributes())->toBeTrue();
});

test('dates are immutable', function () {
    expect(Date::now())->toBeInstanceOf(CarbonImmutable::class);
});

test('the application runs in UTC', function () {
    expect(config('app.timezone'))->toBe('UTC');
});

test('logs go to stderr as JSON', function () {
    expect(config('logging.default'))->toBe('stderr')
        ->and(config('logging.channels.stderr.formatter'))->toBe(JsonFormatter::class);
});
