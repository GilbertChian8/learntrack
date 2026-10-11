<?php

namespace App\Queries;

use App\Enums\ContentType;
use App\Enums\DerivedStatus;
use App\Queries\Results\AssignmentStatus;
use App\Queries\Results\LearnerProgress;
use App\Queries\Results\StatusCounts;
use App\Support\InstitutionTime;
use Carbon\CarbonImmutable;
use stdClass;

/**
 * One learner's progress in one group, built from their pair rows.
 */
class LearnerAggregation
{
    /**
     * @param  iterable<stdClass>  $pairRows  the learner's rows in one group, in due date order
     */
    public static function fromRows(int $learnerId, string $learnerName, iterable $pairRows, InstitutionTime $institutionTime, CarbonImmutable $now): LearnerProgress
    {
        $statuses = [];
        $overduePairs = [];
        $completedQuestionSets = [];

        foreach ($pairRows as $row) {
            $status = DerivedStatus::from($row->status);
            $type = ContentType::from($row->type);

            $statuses[] = new AssignmentStatus(
                assignmentId: $row->assignment_id,
                title: $row->title,
                type: $type,
                dueAt: $institutionTime->fromUtc((string) $row->due_at),
                status: $status,
                score: $row->score,
                completedAt: $institutionTime->fromUtc($row->completed_at),
            );

            if ($status === DerivedStatus::Overdue) {
                $overduePairs[] = $row;
            }

            if ($status === DerivedStatus::Completed && $type === ContentType::QuestionSet && $row->score !== null) {
                $completedQuestionSets[] = $row;
            }
        }

        $averageScore = BehindRule::averageScore($completedQuestionSets);
        $tally = array_count_values(array_map(fn (AssignmentStatus $status) => $status->status->value, $statuses));

        return new LearnerProgress(
            learnerId: $learnerId,
            learnerName: $learnerName,
            behind: BehindRule::isBehind(count($overduePairs), $averageScore),
            reasons: BehindRule::reasons($overduePairs, $completedQuestionSets, $institutionTime, $now),
            averageScore: $averageScore,
            counts: new StatusCounts(
                notStarted: $tally[DerivedStatus::NotStarted->value] ?? 0,
                inProgress: $tally[DerivedStatus::InProgress->value] ?? 0,
                completed: $tally[DerivedStatus::Completed->value] ?? 0,
                overdue: $tally[DerivedStatus::Overdue->value] ?? 0,
            ),
            statuses: $statuses,
        );
    }
}
