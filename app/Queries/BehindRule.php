<?php

namespace App\Queries;

use App\Queries\Results\LowAverageReason;
use App\Queries\Results\OverdueReason;
use App\Queries\Results\Reason;
use App\Support\InstitutionTime;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use stdClass;

/**
 * The behind rule (requirement 6). The only place that knows the threshold.
 */
class BehindRule
{
    public const int SCORE_THRESHOLD = 50;

    /**
     * @var array<string, int>
     */
    private static array $dayNumbers = [];

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
        $today = self::dayNumber($institutionTime->in($now)->toDateString());

        $reasons = array_map(function (stdClass $pair) use ($institutionTime, $today): Reason {
            $dueAt = $institutionTime->fromUtc((string) $pair->due_at);

            return new OverdueReason($pair->assignment_id, $pair->title, $dueAt, $today - self::dayNumber($dueAt->toDateString()), $institutionTime);
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
     * Days since 1970-01-01 for a local Y-m-d date, so a clock change does not count. Cached per date.
     */
    private static function dayNumber(string $date): int
    {
        return self::$dayNumbers[$date] ??= intdiv((new DateTimeImmutable($date, new DateTimeZone('UTC')))->getTimestamp(), 86400);
    }
}
