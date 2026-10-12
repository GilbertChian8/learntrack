<?php

namespace App\Http\Controllers\Api;

use App\Actions\ChangeDueDate;
use App\Actions\RemoveAssignment;
use App\Enums\AuditChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\DueDateRequest;
use App\Http\Resources\ChangeDueDateResultResource;
use App\Models\User;
use App\Support\Scope;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Response;

class AssignmentController extends Controller
{
    public function update(DueDateRequest $request, string $assignment, #[CurrentUser] User $educator, ChangeDueDate $changeDueDate): ChangeDueDateResultResource
    {
        $active = Scope::assignment($educator, (int) $assignment);

        return new ChangeDueDateResultResource(
            $changeDueDate->handle($educator, $active, $request->dueAt($active->group->institution), AuditChannel::Rest),
        );
    }

    public function destroy(string $assignment, #[CurrentUser] User $educator, RemoveAssignment $removeAssignment): Response
    {
        $removeAssignment->handle($educator, Scope::removableAssignment($educator, (int) $assignment), AuditChannel::Rest);

        return response()->noContent();
    }
}
