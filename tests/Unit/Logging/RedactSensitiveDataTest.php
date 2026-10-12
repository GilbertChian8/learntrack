<?php

use App\Enums\Role;
use App\Logging\RedactSensitiveData;
use Illuminate\Http\Request;
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

test('a JsonSerializable object loses the same keys as an array', function () use ($redact) {
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

test('any other object is logged by its class name only', function () use ($redact) {
    $row = (object) ['user_id' => 5, 'name' => 'Sara Lindqvist', 'contact' => 'sara@example.edu'];
    $text = new class implements Stringable
    {
        public function __toString(): string
        {
            return 'Invite sent to Sara Lindqvist';
        }
    };

    expect($redact(['row' => $row, 'text' => $text])->context)->toBe(['row' => stdClass::class, 'text' => $text::class]);
});

test('a request is logged by its class name, without its Authorization header, cookie or body', function () use ($redact) {
    $request = Request::create(
        '/api/v1/groups',
        'POST',
        cookies: ['laravel_session' => 'cookie-5d1e'],
        server: ['HTTP_AUTHORIZATION' => 'Bearer tok-8f2c', 'CONTENT_TYPE' => 'application/json'],
        content: '{"note":"Body Marker"}',
    );

    $record = $redact(['request' => $request]);
    $json = (string) json_encode($record->toArray());

    expect($record->context)->toBe(['request' => Request::class])
        ->and(array_filter(['tok-8f2c', 'cookie-5d1e', 'Body Marker'], fn (string $secret) => str_contains($json, $secret)))->toBe([]);
});

test('dates and enums stay as they are', function () use ($redact) {
    $date = new DateTimeImmutable('2026-10-12 09:00:00');

    expect($redact(['at' => $date, 'role' => Role::Learner])->context)->toBe(['at' => $date, 'role' => Role::Learner]);
});

test('an object that points to itself stops at nine levels', function () use ($redact) {
    $node = new class implements JsonSerializable
    {
        /**
         * @return array<string, mixed>
         */
        public function jsonSerialize(): array
        {
            return ['id' => 1, 'next' => $this];
        }
    };

    $logged = $redact(['node' => $node])->context;

    expect(data_get($logged, 'node'.str_repeat('.next', 9)))->toBe('[too deep]')
        ->and(data_get($logged, 'node'.str_repeat('.next', 8).'.id'))->toBe(1);
});
