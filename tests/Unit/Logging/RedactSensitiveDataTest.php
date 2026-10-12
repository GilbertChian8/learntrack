<?php

use App\Logging\RedactSensitiveData;
use Monolog\Level;
use Monolog\LogRecord;

$redact = fn (array $context, string $message = 'Something happened.', array $extra = []): LogRecord => (new RedactSensitiveData)(
    new LogRecord(new DateTimeImmutable('2026-10-12 09:00:00'), 'testing', Level::Info, $message, $context, $extra),
);

test('names, emails, passwords and a nested token leave the context, and user_id stays', function () use ($redact) {
    $record = $redact([
        'user_id' => 42,
        'email' => 'sara.lindqvist@example.edu',
        'name' => 'Sara Lindqvist',
        'password' => 'correct horse battery staple',
        'request' => ['session' => ['token' => 'tok-8f2c', 'group_id' => 7]],
    ]);

    expect($record->context)->toBe(['user_id' => 42, 'request' => ['session' => ['group_id' => 7]]]);
});

test('every listed key leaves the context at any depth, whatever its case', function () use ($redact) {
    $secrets = [
        'name' => 'x', 'email' => 'x', 'password' => 'x', 'password_confirmation' => 'x', 'token' => 'x',
        'access_token' => 'x', 'refresh_token' => 'x', 'authorization' => 'x', 'cookie' => 'x',
        'Authorization' => 'Bearer x', 'Cookie' => 'x', 'EMAIL' => 'x',
    ];

    $record = $redact([...$secrets, 'request' => ['headers' => [...$secrets, 'accept' => 'application/json']]], extra: $secrets);

    expect($record->context)->toBe(['request' => ['headers' => ['accept' => 'application/json']]])
        ->and($record->extra)->toBe([]);
});

test('a string value that looks like an email becomes [redacted]', function () use ($redact) {
    expect($redact(['contact' => 'a@b.c', 'list' => [1, 'a@b.c']])->context)
        ->toBe(['contact' => '[redacted]', 'list' => [1, '[redacted]']]);
});

test('an email inside a longer string, in the message or in extra is redacted too', function () use ($redact) {
    $record = $redact(
        ['reason' => 'No account for sara@example.edu yet'],
        'Sign-in failed for sara@example.edu.',
        ['note' => 'tom.becker@example.edu'],
    );

    expect($record->message)->toBe('Sign-in failed for [redacted].')
        ->and($record->context)->toBe(['reason' => 'No account for [redacted] yet'])
        ->and($record->extra)->toBe(['note' => '[redacted]']);
});

test('an exception keeps its class, code and file, and loses the email in its message', function () use ($redact) {
    $exception = new RuntimeException("Duplicate entry 'sara@example.edu' for key 'users_email_unique'", 23000);

    $logged = $redact(['exception' => $exception])->context['exception'];

    expect($logged)->toMatchArray([
        'class' => RuntimeException::class,
        'message' => "Duplicate entry '[redacted]' for key 'users_email_unique'",
        'code' => 23000,
    ])->and($logged['file'])->toStartWith(__FILE__.':');
});

test('an object that turns into JSON is checked like an array', function () use ($redact) {
    $user = new class implements JsonSerializable
    {
        /**
         * @return array<string, mixed>
         */
        public function jsonSerialize(): array
        {
            return ['id' => 5, 'name' => 'Sara Lindqvist', 'email' => 'sara@example.edu', 'role' => 'learner'];
        }
    };

    expect($redact(['user' => $user])->context)->toBe(['user' => ['id' => 5, 'role' => 'learner']]);
});
