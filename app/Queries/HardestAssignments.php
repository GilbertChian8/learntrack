<?php

namespace App\Queries;

use App\Models\User;
use App\Queries\Results\AssignmentStats;
use App\Queries\Results\HardestAssignmentsResult;
use App\Queries\Results\RankedAssignment;
use Illuminate\Support\Str;

/**
 * A group's assignments ranked by difficulty, from GroupProgress. Assignments that target nobody are left out.
 */
class HardestAssignments
{
    public static function for(User $educator, int $groupId, int $limit): HardestAssignmentsResult
    {
        $progress = GroupProgress::for($educator, $groupId);

        $assignments = array_values(array_filter($progress->assignments, fn (AssignmentStats $assignment) => $assignment->targetedCount > 0));

        usort($assignments, fn (AssignmentStats $a, AssignmentStats $b) => self::rank($a) <=> self::rank($b));

        return new HardestAssignmentsResult(
            groupId: $progress->groupId,
            groupName: $progress->groupName,
            assignments: array_map(
                fn (AssignmentStats $assignment) => new RankedAssignment($assignment, self::reason($assignment)),
                array_slice($assignments, 0, $limit),
            ),
        );
    }

    /**
     * Lowest completion rate, then lowest average with no average last, then due date, then id.
     *
     * @return array{float, bool, float, int, int}
     */
    private static function rank(AssignmentStats $assignment): array
    {
        return [
            $assignment->completionRate,
            $assignment->averageScore === null,
            $assignment->averageScore ?? 0.0,
            $assignment->dueAt->getTimestamp(),
            $assignment->id,
        ];
    }

    /**
     * For example "12 of 42 learners completed it (29%), average score 48".
     */
    private static function reason(AssignmentStats $assignment): string
    {
        $reason = "{$assignment->completedCount} of {$assignment->targetedCount} ".Str::plural('learner', $assignment->targetedCount)
            .' completed it ('.(int) round($assignment->completionRate * 100).'%)';

        if ($assignment->averageScore === null) {
            return $reason;
        }

        $average = $assignment->averageScore === floor($assignment->averageScore)
            ? (string) (int) $assignment->averageScore
            : number_format($assignment->averageScore, 1, '.', '');

        return "{$reason}, average score {$average}";
    }
}
