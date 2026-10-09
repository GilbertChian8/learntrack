<?php

use App\Enums\AssignmentScope;
use App\Enums\AuditAction;
use App\Enums\AuditChannel;
use App\Enums\ContentType;
use App\Enums\DerivedStatus;
use App\Enums\ProgressStatus;
use App\Enums\Role;

test('enums carry the exact strings of the contract', function (string $enum, array $values) {
    expect(array_column($enum::cases(), 'value'))->toBe($values);
})->with([
    'role' => [Role::class, ['educator', 'learner']],
    'content type' => [ContentType::class, ['article', 'question_set']],
    'assignment scope' => [AssignmentScope::class, ['group', 'learners']],
    'progress status' => [ProgressStatus::class, ['in_progress', 'completed']],
    'derived status' => [DerivedStatus::class, ['not_started', 'in_progress', 'completed', 'overdue']],
    'audit channel' => [AuditChannel::class, ['rest', 'mcp']],
    'audit action' => [AuditAction::class, [
        'group.created',
        'group.learner_added',
        'group.learner_removed',
        'assignment.created',
        'assignment.learners_added',
        'assignment.due_changed',
        'assignment.removed',
    ]],
]);
