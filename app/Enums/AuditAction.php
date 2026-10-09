<?php

namespace App\Enums;

enum AuditAction: string
{
    case GroupCreated = 'group.created';
    case GroupLearnerAdded = 'group.learner_added';
    case GroupLearnerRemoved = 'group.learner_removed';
    case AssignmentCreated = 'assignment.created';
    case AssignmentLearnersAdded = 'assignment.learners_added';
    case AssignmentDueChanged = 'assignment.due_changed';
    case AssignmentRemoved = 'assignment.removed';
}
