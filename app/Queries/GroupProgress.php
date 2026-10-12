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
use Illuminate\Support\Enumerable;
use stdClass;

/**
 * One group's progress in two queries: the group and its members, then the pair rows,
 * read one learner at a time.
 *
 * @phpstan-type AssignmentTotals array{row: stdClass, targeted: int, completed: int, scoreSum: int, scoreCount: int}
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

        $progressByLearner = [];
        $totalsByAssignment = [];

        $rowsPerLearner = PairRows::forGroup($groupId, $now)->cursor()
            ->chunkWhile(fn (stdClass $row, int $key, Enumerable $rows) => $row->user_id === $rows->last()->user_id);

        foreach ($rowsPerLearner as $rows) {
            foreach ($rows as $row) {
                $totalsByAssignment[$row->assignment_id] = self::addToTotals($totalsByAssignment[$row->assignment_id] ?? null, $row);
            }

            $learner = $rows->first();

            if ($learner->user_id !== null) {
                $progressByLearner[$learner->user_id] = LearnerAggregation::fromRows($learner->user_id, $learner->learner_name, $rows, $institutionTime, $now);
            }
        }

        $learners = [];

        foreach ($members as $member) {
            if ($member->user_id !== null) {
                $learners[] = $progressByLearner[$member->user_id]
                    ?? LearnerAggregation::fromRows($member->user_id, $member->learner_name, [], $institutionTime, $now);
            }
        }

        usort($totalsByAssignment, fn (array $a, array $b) => [$a['row']->due_at, $a['row']->assignment_id] <=> [$b['row']->due_at, $b['row']->assignment_id]);

        return new GroupProgressResult(
            groupId: $group->group_id,
            groupName: $group->group_name,
            institutionName: $group->institution_name,
            generatedAt: $institutionTime->in($now),
            learnerCount: count($learners),
            behindCount: count(array_filter($learners, fn (LearnerProgress $learner) => $learner->behind)),
            learners: $learners,
            assignments: array_map(fn (array $totals) => self::assignmentStats($totals, $institutionTime), $totalsByAssignment),
        );
    }

    /**
     * A row with a null learner adds the assignment to the list but targets nobody.
     *
     * @param  AssignmentTotals|null  $totals
     * @return AssignmentTotals
     */
    private static function addToTotals(?array $totals, stdClass $row): array
    {
        $totals ??= ['row' => $row, 'targeted' => 0, 'completed' => 0, 'scoreSum' => 0, 'scoreCount' => 0];

        if ($row->user_id === null) {
            return $totals;
        }

        $totals['targeted']++;

        if ($row->status === DerivedStatus::Completed->value) {
            $totals['completed']++;

            if ($row->type === ContentType::QuestionSet->value && $row->score !== null) {
                $totals['scoreSum'] += $row->score;
                $totals['scoreCount']++;
            }
        }

        return $totals;
    }

    /**
     * @param  AssignmentTotals  $totals
     */
    private static function assignmentStats(array $totals, InstitutionTime $institutionTime): AssignmentStats
    {
        $assignment = $totals['row'];

        return new AssignmentStats(
            id: $assignment->assignment_id,
            title: $assignment->title,
            topic: $assignment->topic,
            type: ContentType::from($assignment->type),
            dueAt: $institutionTime->fromUtc((string) $assignment->due_at),
            scope: AssignmentScope::from($assignment->scope),
            targetedCount: $totals['targeted'],
            completedCount: $totals['completed'],
            completionRate: $totals['targeted'] === 0 ? 0.0 : round($totals['completed'] / $totals['targeted'], 2),
            averageScore: $totals['scoreCount'] === 0 ? null : round($totals['scoreSum'] / $totals['scoreCount'], 1),
        );
    }
}
