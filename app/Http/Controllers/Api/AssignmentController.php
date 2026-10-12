<?php

namespace App\Http\Controllers\Api;

use App\Actions\ChangeDueDate;
use App\Actions\RemoveAssignment;
use App\Enums\AuditChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\DueDateRequest;
use App\Http\Resources\ChangeDueDateResultResource;
use App\Models\Assignment;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Response;

class AssignmentController extends Controller
{
    public function update(DueDateRequest $request, Assignment $assignment, #[CurrentUser] User $educator, ChangeDueDate $changeDueDate): ChangeDueDateResultResource
    {
        return new ChangeDueDateResultResource(
            $changeDueDate->handle($educator, $assignment, $request->dueAt($assignment->group->institution), AuditChannel::Rest),
        );
    }

    public function destroy(Assignment $removableAssignment, #[CurrentUser] User $educator, RemoveAssignment $removeAssignment): Response
    {
        $removeAssignment->handle($educator, $removableAssignment, AuditChannel::Rest);

        return response()->noContent();
    }
}
