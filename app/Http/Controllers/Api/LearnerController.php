<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPage;
use App\Http\Requests\SearchRequest;
use App\Http\Resources\LearnerWithEmailResource;
use App\Models\User;
use App\Policies\GroupPolicy;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;

class LearnerController extends Controller
{
    public function __invoke(SearchRequest $request, #[CurrentUser] User $educator): CursorPage
    {
        $learners = GroupPolicy::addableLearners($educator)
            ->when($request->searchPattern(), fn (Builder $query, string $pattern) => $query->where(
                fn (Builder $query) => $query->whereLike('users.name', $pattern)->orWhereLike('users.email', $pattern),
            ))
            ->orderBy('users.name')
            ->orderBy('users.id');

        return CursorPage::of($learners, $request, LearnerWithEmailResource::class);
    }
}
