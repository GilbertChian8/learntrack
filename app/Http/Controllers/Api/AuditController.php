<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPage;
use App\Http\Requests\ListRequest;
use App\Http\Resources\AuditEntryResource;
use App\Models\Group;
use App\Queries\GroupAudit;

class AuditController extends Controller
{
    public function __invoke(ListRequest $request, Group $group): CursorPage
    {
        return CursorPage::of(GroupAudit::for($group), $request, AuditEntryResource::class);
    }
}
