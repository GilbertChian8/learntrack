<?php

namespace App\Queries;

use App\Queries\Results\LowAverageReason;
use App\Queries\Results\OverdueReason;
use App\Queries\Results\Reason;
use App\Support\InstitutionTime;
use Carbon\CarbonImmutable;
use stdClass;

/**
 * The behind rule (requirement 6). The only place that knows the threshold.
 */
class BehindRule
{
    public const int SCORE_THRESHOLD = 50;

    public static function isBehind(int $overdueCount, ?float $averageScore): bool
    {
        return $overdueCount > 0 || self::isLow($averageScore);
    }

    /**
     * The average score of the completed question sets, rounded to 1 decimal.
     *
     * @param  list<stdClass>  $completedQuestionSets  pair rows
     */
    public static function averageScore(array $completedQuestionSets): ?float
    {
        if ($completedQuestionSets === []) {
            return null;
        }

        $scores = array_column($completedQuestionSets, 'score');

        return round(array_sum($scores) / count($scores), 1);
    }

    /**
     * @param  list<stdClass>  $overduePairs  pair rows in due date order
     * @param  list<stdClass>  $completedQuestionSets  pair rows in due date order
     * @return list<Reason>
     */
    public static function reasons(array $overduePairs, array $completedQuestionSets, InstitutionTime $institutionTime, CarbonImmutable $now): array
    {
        $today = $institutionTime->in($now)->startOfDay();

        $reasons = array_map(function (stdClass $pair) use ($institutionTime, $today): Reason {
            $dueAt = $institutionTime->fromUtc((string) $pair->due_at);

            return new OverdueReason($pair->assignment_id, $pair->title, $dueAt, self::calendarDays($dueAt, $today));
        }, $overduePairs);

        $averageScore = self::averageScore($completedQuestionSets);

        if (self::isLow($averageScore)) {
            $reasons[] = new LowAverageReason($averageScore, array_map(fn (stdClass $pair) => [
                'assignment_id' => $pair->assignment_id,
                'title' => $pair->title,
                'score' => $pair->score,
            ], $completedQuestionSets));
        }

        return $reasons;
    }

    public static function ruleText(): string
    {
        return 'A learner is behind when they have at least one overdue assignment in the group, '
            .'or their average score over completed question sets in the group is below '.self::SCORE_THRESHOLD.'.';
    }

    /**
     * @phpstan-assert-if-true float $averageScore
     */
    private static function isLow(?float $averageScore): bool
    {
        return $averageScore !== null && $averageScore < self::SCORE_THRESHOLD;
    }

    /**
     * Rounded, since a day with a clock change has 23 or 25 hours.
     */
    private static function calendarDays(CarbonImmutable $dueAt, CarbonImmutable $today): int
    {
        return (int) round($dueAt->startOfDay()->diffInDays($today));
    }
}
