<?php

namespace App\Enums;

/**
 * The status the API returns for a learner and assignment pair, derived at
 * read time and never stored (ADR-008).
 */
enum DerivedStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Overdue = 'overdue';
}
