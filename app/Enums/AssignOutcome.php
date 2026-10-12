<?php

namespace App\Enums;

enum AssignOutcome: string
{
    case Created = 'created';
    case LearnersAdded = 'learners_added';
    case Unchanged = 'unchanged';
}
