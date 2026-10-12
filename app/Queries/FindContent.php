<?php

namespace App\Queries;

use App\Models\ContentItem;
use Illuminate\Database\Eloquent\Collection;

/**
 * A content item of the whole library, by id or by part of its title.
 */
class FindContent
{
    public static function byId(int $id): ?ContentItem
    {
        return ContentItem::query()->find($id);
    }

    /**
     * Case-insensitive, anywhere in the title, ordered by title then id.
     *
     * @return Collection<int, ContentItem>
     */
    public static function byTitle(string $title): Collection
    {
        return ContentItem::query()
            ->whereLike('title', '%'.addcslashes($title, '\\%_').'%')
            ->orderBy('title')
            ->orderBy('id')
            ->get();
    }
}
