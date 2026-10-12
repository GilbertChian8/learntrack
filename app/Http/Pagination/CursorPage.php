<?php

namespace App\Http\Pagination;

use App\Http\Requests\ListRequest;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Validation\ValidationException;
use stdClass;
use UnexpectedValueException;

/**
 * One page of a list: { "data": [...], "meta": { "next_cursor": ... } }.
 */
class CursorPage extends ResourceCollection
{
    /**
     * @param  CursorPaginator<int, covariant Model|stdClass>  $paginator
     * @param  class-string<JsonResource>  $collects
     */
    public function __construct(CursorPaginator $paginator, string $collects)
    {
        $this->collects = $collects;

        parent::__construct($paginator);
    }

    /**
     * Pages the query in its own order. A cursor made for another list answers 422.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>|QueryBuilder  $query
     * @param  class-string<JsonResource>  $collects
     *
     * @throws ValidationException
     */
    public static function of(Builder|QueryBuilder $query, ListRequest $request, string $collects): self
    {
        try {
            $paginator = $query->cursorPaginate($request->limit(), cursor: $request->cursor());
        } catch (UnexpectedValueException) {
            throw ValidationException::withMessages(['cursor' => ListRequest::INVALID_CURSOR]);
        }

        return new self($paginator, $collects);
    }

    /**
     * @param  array{next_cursor: string|null}  $paginated
     * @param  array<string, mixed>  $default
     * @return array{meta: array{next_cursor: string|null}}
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        return ['meta' => ['next_cursor' => $paginated['next_cursor']]];
    }
}
