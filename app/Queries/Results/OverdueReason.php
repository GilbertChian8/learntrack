<?php

namespace App\Queries\Results;

use App\Support\InstitutionTime;
use Carbon\CarbonImmutable;

readonly class OverdueReason implements Reason
{
    public function __construct(
        public int $assignmentId,
        public string $title,
        public CarbonImmutable $dueAt,
        public int $daysOverdue,
    ) {}

    public function text(): string
    {
        $since = match ($this->daysOverdue) {
            0 => 'earlier today',
            1 => '1 day overdue',
            default => "{$this->daysOverdue} days overdue",
        };

        return "Overdue: {$this->title}, due {$this->dueAt->toDateString()}, {$since}";
    }

    /**
     * @return array{kind: 'overdue', assignment_id: int, title: string, due_at: string, days_overdue: int, text: string}
     */
    public function toArray(): array
    {
        return [
            'kind' => 'overdue',
            'assignment_id' => $this->assignmentId,
            'title' => $this->title,
            'due_at' => InstitutionTime::iso($this->dueAt),
            'days_overdue' => $this->daysOverdue,
            'text' => $this->text(),
        ];
    }
}
