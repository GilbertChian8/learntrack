<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPage;
use App\Http\Requests\SearchRequest;
use App\Http\Resources\LearnerWithEmailResource;
use App\Models\User;
use App\Queries\LearnerDirectory;
use Illuminate\Container\Attributes\CurrentUser;

class LearnerController extends Controller
{
    public function __invoke(SearchRequest $request, #[CurrentUser] User $educator): CursorPage
    {
        return CursorPage::of(LearnerDirectory::for($educator, $request->search()), $request, LearnerWithEmailResource::class);
    }
}
