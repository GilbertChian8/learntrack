<?php

namespace App\Http\Controllers\Api;

use App\Actions\AssignContent;
use App\Enums\AssignOutcome;
use App\Enums\AuditChannel;
use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPage;
use App\Http\Requests\AssignContentRequest;
use App\Http\Requests\ListRequest;
use App\Http\Resources\AssignmentResource;
use App\Http\Resources\AssignResultResource;
use App\Models\Group;
use App\Models\User;
use App\Queries\GroupAssignments;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class GroupAssignmentController extends Controller
{
    public function index(ListRequest $request, Group $group): CursorPage
    {
        return CursorPage::of(GroupAssignments::for($group), $request, AssignmentResource::class);
    }

    public function store(AssignContentRequest $request, Group $group, #[CurrentUser] User $educator, AssignContent $assignContent): JsonResponse
    {
        $result = $assignContent->handle(
            $educator,
            $group,
            $request->contentItem(),
            $request->dueAt($group->institution),
            $request->learnerIds(),
            AuditChannel::Rest,
        );

        return (new AssignResultResource($result))
            ->response()
            ->setStatusCode($result->result === AssignOutcome::Created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }
}
