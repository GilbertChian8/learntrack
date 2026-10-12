<?php

namespace App\Http\Controllers\Api\Me;

use App\Actions\RecordProgress;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecordProgressRequest;
use App\Http\Resources\ProgressResource;
use App\Models\Assignment;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;

class ProgressController extends Controller
{
    public function __invoke(RecordProgressRequest $request, Assignment $learnerAssignment, #[CurrentUser] User $learner, RecordProgress $recordProgress): ProgressResource
    {
        return new ProgressResource(
            $recordProgress->handle($learner, $learnerAssignment, $request->status(), $request->score()),
        );
    }
}
