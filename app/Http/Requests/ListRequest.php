<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Pagination\Cursor;

/**
 * The paging query of every list: ?limit (1 to 200, default 50) and ?cursor.
 */
class ListRequest extends FormRequest
{
    public const string INVALID_CURSOR = 'The cursor is invalid.';

    private const int DEFAULT_LIMIT = 50;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'limit' => ['nullable', 'integer', 'between:1,200'],
            'cursor' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! self::isCursor($value)) {
                    $fail(self::INVALID_CURSOR);
                }
            }],
        ];
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? self::DEFAULT_LIMIT);
    }

    public function cursor(): ?string
    {
        return $this->validated('cursor');
    }

    private static function isCursor(mixed $value): bool
    {
        $cursor = Cursor::fromEncoded($value);

        return $cursor !== null && collect($cursor->toArray())->every(fn (mixed $parameter) => is_scalar($parameter));
    }
}
