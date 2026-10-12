<?php

namespace App\Logging;

use DateTimeInterface;
use JsonSerializable;
use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * Removes names, emails, passwords and tokens from a log record, at any depth.
 */
class RedactSensitiveData implements ProcessorInterface
{
    private const array KEYS = [
        'name', 'email', 'password', 'password_confirmation', 'token',
        'access_token', 'refresh_token', 'authorization', 'cookie',
    ];

    private const string EMAIL = '/[^\s@\'"<>(),;:]+@[^\s@\'"<>(),;:]+\.[^\s@\'"<>(),;:.]+/';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->redactString($record->message),
            context: $this->redactArray($record->context),
            extra: $this->redactArray($record->extra),
        );
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function redactArray(array $values): array
    {
        $redacted = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::KEYS, true)) {
                continue;
            }

            $redacted[$key] = $this->redact($value);
        }

        return $redacted;
    }

    private function redact(mixed $value): mixed
    {
        return match (true) {
            is_string($value) => $this->redactString($value),
            is_array($value) => $this->redactArray($value),
            $value instanceof Throwable => $this->redact((new JsonFormatter)->normalizeValue($value)),
            $value instanceof JsonSerializable => $this->redact($value->jsonSerialize()),
            $value instanceof DateTimeInterface, $value instanceof UnitEnum => $value,
            $value instanceof Stringable => $this->redactString((string) $value),
            is_object($value) => $this->redactArray(get_object_vars($value)),
            default => $value,
        };
    }

    private function redactString(string $value): string
    {
        return (string) preg_replace(self::EMAIL, '[redacted]', $value);
    }
}
