<?php

use App\Logging\AddRedactionProcessor;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

test('every log channel runs the redaction processor', function () {
    $channels = collect(Config::array('logging.channels'))->filter(fn (array $channel) => isset($channel['driver']));

    expect($channels)->not->toBeEmpty()
        ->and($channels->reject(fn (array $channel) => in_array(AddRedactionProcessor::class, $channel['tap'] ?? [], true))->keys()->all())->toBe([]);
});

test('a line written on the default channel has no name, email or token, and keeps user_id', function () {
    $lines = captureLogLines();
    $user = User::factory()->learner()->make(['id' => 5, 'name' => 'Sara Lindqvist', 'email' => 'sara@example.edu']);

    Log::warning('Sign-in failed for sara@example.edu.', [
        'user_id' => 5,
        'user' => $user,
        'email' => 'sara@example.edu',
        'request' => ['token' => 'tok-8f2c'],
    ]);

    $line = $lines()[0];

    $json = (string) json_encode($line);

    expect($line['message'])->toBe('Sign-in failed for [redacted].')
        ->and($line['context']['user_id'])->toBe(5)
        ->and(array_intersect(['id', 'name', 'email'], array_keys($line['context']['user'])))->toBe(['id'])
        ->and(array_filter(['sara@example.edu', 'Sara Lindqvist', 'tok-8f2c'], fn (string $secret) => str_contains($json, $secret)))->toBe([]);
});
