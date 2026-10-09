<?php

namespace App\Enums;

/**
 * The status a progress row stores. "Not started" is the absence of a row.
 */
enum ProgressStatus: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';
}
