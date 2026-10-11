<?php

namespace App\Http\Controllers\Api;

use App\Actions\CreateGroup;
use App\Enums\AuditChannel;
use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPage;
use App\Http\Requests\CreateGroupRequest;
use App\Http\Requests\ListRequest;
use App\Http\Resources\GroupDetailResource;
use App\Http\Resources\GroupResource;
use App\Models\Group;
use App\Models\User;
use App\Queries\MyGroups;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class GroupController extends Controller
{
    public function index(ListRequest $request, #[CurrentUser] User $educator): CursorPage
    {
        return CursorPage::of(MyGroups::for($educator), $request, GroupResource::class);
    }

    public function store(CreateGroupRequest $request, #[CurrentUser] User $educator, CreateGroup $createGroup): JsonResponse
    {
        $group = $createGroup->handle($educator, $request->name(), AuditChannel::Rest);

        return (new GroupResource(MyGroups::for($educator)->whereKey($group->id)->sole()))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Group $group, #[CurrentUser] User $educator): GroupDetailResource
    {
        return new GroupDetailResource(MyGroups::for($educator)->whereKey($group->id)->sole()->load([
            'educators' => fn (BelongsToMany $query) => $query->orderBy('users.name')->orderBy('users.id'),
            'learners' => fn (BelongsToMany $query) => $query->orderBy('users.name')->orderBy('users.id'),
        ]));
    }
}
