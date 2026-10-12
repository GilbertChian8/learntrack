<?php

namespace App\Http\Controllers\Api\Me;

use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPage;
use App\Http\Requests\ListRequest;
use App\Http\Resources\LearnerAssignmentResource;
use App\Models\User;
use App\Queries\LearnerAssignments;
use Illuminate\Container\Attributes\CurrentUser;

class AssignmentController extends Controller
{
    public function __invoke(ListRequest $request, #[CurrentUser] User $learner): CursorPage
    {
        return CursorPage::of(LearnerAssignments::for($learner), $request, LearnerAssignmentResource::class);
    }
}
