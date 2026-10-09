<?php

namespace App\Support;

use App\Exceptions\NotFoundException;
use App\Models\Assignment;
use App\Models\Group;
use App\Models\User;
use App\Policies\AssignmentPolicy;
use App\Policies\GroupPolicy;
use App\Policies\ProgressPolicy;

/**
 * The one place that turns "not in scope" into NotFoundException (ADR-006).
 */
class Scope
{
    public static function group(User $educator, int $id): Group
    {
        return GroupPolicy::taughtBy($educator)->whereKey($id)->firstOr(fn () => throw NotFoundException::group());
    }

    public static function assignment(User $educator, int $id): Assignment
    {
        return AssignmentPolicy::manageableBy($educator)->whereKey($id)->firstOr(fn () => throw NotFoundException::assignment());
    }

    public static function learnerAssignment(User $learner, int $id): Assignment
    {
        return ProgressPolicy::targeting($learner)->whereKey($id)->firstOr(fn () => throw NotFoundException::assignment());
    }
}
