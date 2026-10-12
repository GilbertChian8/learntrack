<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPage;
use App\Http\Requests\ContentSearchRequest;
use App\Http\Resources\ContentResource;
use App\Queries\ContentLibrary;

class ContentController extends Controller
{
    public function __invoke(ContentSearchRequest $request): CursorPage
    {
        return CursorPage::of(ContentLibrary::for($request->topic(), $request->search()), $request, ContentResource::class);
    }
}
