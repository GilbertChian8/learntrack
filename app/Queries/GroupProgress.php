<?php

namespace App\Queries;

use App\Enums\AssignmentScope;
use App\Enums\ContentType;
use App\Enums\DerivedStatus;
use App\Exceptions\NotFoundException;
use App\Models\User;
use App\Policies\GroupPolicy;
use App\Queries\Results\AssignmentStats;
use App\Queries\Results\GroupProgressResult;
use App\Queries\Results\LearnerProgress;
use App\Support\InstitutionTime;
use Carbon\CarbonImmutable;
use stdClass;

/**
 * One group's progress in two queries: the group and its members, then the pair rows.
 */
class GroupProgress
{
    public static function for(User $educator, int $groupId): GroupProgressResult
    {
        $members = GroupPolicy::taughtBy($educator)
            ->whereKey($groupId)
            ->join('institutions', 'institutions.id', '=', 'learner_groups.institution_id')
            ->leftJoin('group_learners', 'group_learners.group_id', '=', 'learner_groups.id')
            ->leftJoin('users', 'users.id', '=', 'group_learners.user_id')
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->toBase()
            ->get([
                'learner_groups.id as group_id',
                'learner_groups.name as group_name',
                'institutions.name as institution_name',
                'institutions.timezone',
                'users.id as user_id',
                'users.name as learner_name',
            ]);

        $group = $members->first() ?? throw NotFoundException::group();
        $institutionTime = new InstitutionTime($group->timezone);
        $now = CarbonImmutable::now()->startOfSecond();

        $rowsByAssignment = [];
        $rowsByLearner = [];

        foreach (PairRows::forGroup($groupId, $now)->get() as $row) {
            $rowsByAssignment[$row->assignment_id][] = $row;

            if ($row->user_id !== null) {
                $rowsByLearner[$row->user_id][] = $row;
            }
        }

        $learners = [];

        foreach ($members as $member) {
            if ($member->user_id !== null) {
                $learners[] = LearnerAggregation::fromRows($member->user_id, $member->learner_name, $rowsByLearner[$member->user_id] ?? [], $institutionTime, $now);
            }
        }

        return new GroupProgressResult(
            groupId: $group->group_id,
            groupName: $group->group_name,
            institutionName: $group->institution_name,
            generatedAt: $institutionTime->in($now),
            learnerCount: count($learners),
            behindCount: count(array_filter($learners, fn (LearnerProgress $learner) => $learner->behind)),
            learners: $learners,
            assignments: array_map(fn (array $rows) => self::assignmentStats($rows, $institutionTime), array_values($rowsByAssignment)),
        );
    }

    /**
     * Rows with a null learner count for the list but target nobody.
     *
     * @param  non-empty-list<stdClass>  $rows  one assignment's pair rows
     */
    private static function assignmentStats(array $rows, InstitutionTime $institutionTime): AssignmentStats
    {
        $assignment = $rows[0];
        $type = ContentType::from($assignment->type);
        $targeted = array_filter($rows, fn (stdClass $row) => $row->user_id !== null);
        $completed = array_filter($targeted, fn (stdClass $row) => $row->status === DerivedStatus::Completed->value);
        $scores = $type === ContentType::QuestionSet
            ? array_filter(array_column($completed, 'score'), fn (mixed $score) => $score !== null)
            : [];

        return new AssignmentStats(
            id: $assignment->assignment_id,
            title: $assignment->title,
            topic: $assignment->topic,
            type: $type,
            dueAt: $institutionTime->fromUtc((string) $assignment->due_at),
            scope: AssignmentScope::from($assignment->scope),
            targetedCount: count($targeted),
            completedCount: count($completed),
            completionRate: $targeted === [] ? 0.0 : round(count($completed) / count($targeted), 2),
            averageScore: $scores === [] ? null : round(array_sum($scores) / count($scores), 1),
        );
    }
}
