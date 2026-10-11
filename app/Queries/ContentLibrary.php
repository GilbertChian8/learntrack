<?php

namespace App\Queries;

use App\Models\ContentItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * The content library, ordered by topic, title, then id. The topic matches
 * exactly and the search matches the title anywhere, both case-insensitive.
 */
class ContentLibrary
{
    /**
     * @return Builder<ContentItem>
     */
    public static function for(?string $topic = null, ?string $search = null): Builder
    {
        $content = ContentItem::query()
            ->orderBy('topic')
            ->orderBy('title')
            ->orderBy('id');

        if ($topic !== null) {
            $content->where('topic', $topic);
        }

        if ($search !== null) {
            $content->whereLike('title', '%'.addcslashes($search, '\\%_').'%');
        }

        return $content;
    }
}
