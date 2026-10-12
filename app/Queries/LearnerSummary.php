<?php

namespace App\Queries;

use App\Exceptions\NotFoundException;
use App\Models\Group;
use App\Models\User;
use App\Policies\GroupPolicy;
use App\Queries\Results\LearnerCandidate;
use App\Queries\Results\LearnerGroupProgress;
use App\Queries\Results\LearnerSummaryResult;
use App\Support\InstitutionTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use stdClass;

/**
 * One learner in the groups an educator teaches, and the name search that finds them.
 */
class LearnerSummary
{
    /**
     * Two queries: the educator's groups that contain the learner, then the learner's pair rows in them.
     */
    public static function for(User $educator, int $learnerId): LearnerSummaryResult
    {
        $groups = self::taughtGroupsWithLearners($educator)
            ->join('institutions', 'institutions.id', '=', 'learner_groups.institution_id')
            ->where('group_learners.user_id', $learnerId)
            ->orderBy('learner_groups.name')
            ->orderBy('learner_groups.id')
            ->toBase()
            ->get([
                'learner_groups.id as group_id',
                'learner_groups.name as group_name',
                'institutions.timezone',
                'users.name as learner_name',
            ]);

        $learner = $groups->first() ?? throw NotFoundException::learner();
        $now = CarbonImmutable::now()->startOfSecond();
        $rowsByGroup = PairRows::forLearner($learnerId, $now, $groups->pluck('group_id')->all())->get()->groupBy('group_id');

        return new LearnerSummaryResult(
            learnerId: $learnerId,
            learnerName: $learner->learner_name,
            groups: $groups->map(fn (stdClass $group) => new LearnerGroupProgress(
                groupId: $group->group_id,
                groupName: $group->group_name,
                progress: LearnerAggregation::fromRows(
                    $learnerId,
                    $learner->learner_name,
                    $rowsByGroup->get($group->group_id, []),
                    new InstitutionTime($group->timezone),
                    $now,
                ),
            ))->all(),
        );
    }

    /**
     * The learners of the educator's groups whose name contains $name, case-insensitive,
     * ordered by name then id, each with their group names. One query.
     *
     * @return list<LearnerCandidate>
     */
    public static function candidates(User $educator, string $name): array
    {
        return self::taughtGroupsWithLearners($educator)
            ->whereLike('users.name', '%'.addcslashes($name, '\\%_').'%')
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->orderBy('learner_groups.name')
            ->orderBy('learner_groups.id')
            ->toBase()
            ->get(['users.id as learner_id', 'users.name as learner_name', 'learner_groups.name as group_name'])
            ->groupBy('learner_id')
            ->map(fn (Collection $rows) => new LearnerCandidate(
                id: $rows->first()->learner_id,
                name: $rows->first()->learner_name,
                groupNames: $rows->pluck('group_name')->all(),
            ))
            ->values()
            ->all();
    }

    /**
     * One row per group the educator teaches and learner in it.
     *
     * @return Builder<Group>
     */
    private static function taughtGroupsWithLearners(User $educator): Builder
    {
        return GroupPolicy::taughtBy($educator)
            ->join('group_learners', 'group_learners.group_id', '=', 'learner_groups.id')
            ->join('users', 'users.id', '=', 'group_learners.user_id');
    }
}
