<?php

namespace App\Queries\Results;

use App\Queries\BehindRule;

readonly class LowAverageReason implements Reason
{
    /**
     * @param  list<array{assignment_id: int, title: string, score: int}>  $scores
     */
    public function __construct(
        public float $averageScore,
        public array $scores,
    ) {}

    public function text(): string
    {
        $average = $this->averageScore === floor($this->averageScore)
            ? (string) (int) $this->averageScore
            : number_format($this->averageScore, 1, '.', '');
        $scores = implode(', ', array_column($this->scores, 'score'));

        return "Average question set score {$average} is below ".BehindRule::SCORE_THRESHOLD." (scores: {$scores})";
    }

    /**
     * @return array{kind: 'low_average', average_score: float, threshold: int, scores: list<array{assignment_id: int, title: string, score: int}>, text: string}
     */
    public function toArray(): array
    {
        return [
            'kind' => 'low_average',
            'average_score' => $this->averageScore,
            'threshold' => BehindRule::SCORE_THRESHOLD,
            'scores' => $this->scores,
            'text' => $this->text(),
        ];
    }
}
