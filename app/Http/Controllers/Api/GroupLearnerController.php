<?php

namespace App\Http\Controllers\Api;

use App\Actions\AddLearners;
use App\Actions\RemoveLearner;
use App\Enums\AuditChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddLearnersRequest;
use App\Http\Resources\AddLearnersResultResource;
use App\Models\Group;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Response;

class GroupLearnerController extends Controller
{
    public function store(AddLearnersRequest $request, Group $group, #[CurrentUser] User $educator, AddLearners $addLearners): AddLearnersResultResource
    {
        return new AddLearnersResultResource($addLearners->handle($educator, $group, $request->userIds(), AuditChannel::Rest));
    }

    public function destroy(Group $group, string $user, #[CurrentUser] User $educator, RemoveLearner $removeLearner): Response
    {
        $removeLearner->handle($educator, $group, (int) $user, AuditChannel::Rest);

        return response()->noContent();
    }
}
