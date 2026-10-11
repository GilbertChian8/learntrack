<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPage;
use App\Http\Requests\ContentSearchRequest;
use App\Http\Resources\ContentResource;
use App\Models\ContentItem;
use Illuminate\Database\Eloquent\Builder;

class ContentController extends Controller
{
    public function __invoke(ContentSearchRequest $request): CursorPage
    {
        $content = ContentItem::query()
            ->when($request->topic(), fn (Builder $query, string $topic) => $query->where('topic', $topic))
            ->when($request->searchPattern(), fn (Builder $query, string $pattern) => $query->whereLike('title', $pattern))
            ->orderBy('topic')
            ->orderBy('title')
            ->orderBy('id');

        return CursorPage::of($content, $request, ContentResource::class);
    }
}
