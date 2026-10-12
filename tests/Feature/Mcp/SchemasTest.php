<?php

use App\Mcp\Schemas\AssignmentSchema;
use App\Mcp\Schemas\AssignmentStatsSchema;
use App\Mcp\Schemas\CountsSchema;
use App\Mcp\Schemas\LearnerSchema;
use App\Mcp\Schemas\ReasonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;

test('each shared object in mcp-tools.md section 2 matches its schema', function (Closure $type, string $example) {
    expect(schemaErrors($type(new JsonSchemaTypeFactory)->toArray(), json_decode($example, true)))->toBe([]);
})->with([
    'learner' => [
        fn (JsonSchemaTypeFactory $schema): Type => LearnerSchema::make($schema),
        '{ "id": 101, "name": "Sara Lindqvist" }',
    ],
    'counts' => [
        fn (JsonSchemaTypeFactory $schema): Type => CountsSchema::make($schema),
        '{ "not_started": 3, "in_progress": 2, "completed": 4, "overdue": 1 }',
    ],
    'overdue reason' => [
        fn (JsonSchemaTypeFactory $schema): Type => ReasonSchema::make($schema),
        '{ "kind": "overdue", "assignment_id": 40, "title": "ECG Basics", "due_at": "2026-10-02T23:59:59+02:00", "days_overdue": 7, "text": "Overdue: ECG Basics, due 2026-10-02, 7 days overdue" }',
    ],
    'low average reason' => [
        fn (JsonSchemaTypeFactory $schema): Type => ReasonSchema::make($schema),
        '{ "kind": "low_average", "average_score": 42.5, "threshold": 50, "scores": [ { "assignment_id": 41, "title": "Heart Failure Question Set", "score": 40 }, { "assignment_id": 43, "title": "Arrhythmia Question Set", "score": 45 } ], "text": "Average question set score 42.5 is below 50 (scores: 40, 45)" }',
    ],
    'assignment_stats' => [
        fn (JsonSchemaTypeFactory $schema): Type => AssignmentStatsSchema::make($schema),
        '{ "id": 41, "title": "Heart Failure Question Set", "topic": "Cardiology", "type": "question_set", "due_at": "2026-10-16T23:59:59+02:00", "scope": "learners", "targeted_count": 2, "completed_count": 1, "completion_rate": 0.5, "average_score": 72.0 }',
    ],
    'assignment_stats with no average' => [
        fn (JsonSchemaTypeFactory $schema): Type => AssignmentStatsSchema::make($schema),
        '{ "id": 40, "title": "ECG Basics", "topic": "Cardiology", "type": "article", "due_at": "2026-10-02T23:59:59+02:00", "scope": "group", "targeted_count": 3, "completed_count": 3, "completion_rate": 1, "average_score": null }',
    ],
    'assignment' => [
        fn (JsonSchemaTypeFactory $schema): Type => AssignmentSchema::make($schema),
        '{ "id": 41, "group_id": 12, "content": { "id": 8, "title": "Heart Failure Question Set", "topic": "Cardiology", "type": "question_set", "estimated_minutes": 30 }, "due_at": "2026-10-16T23:59:59+02:00", "scope": "learners", "targeted_count": 2, "learners": [ { "id": 101, "name": "Sara Lindqvist" }, { "id": 102, "name": "Tom Becker" } ], "created_at": "2026-10-09T14:03:21+02:00" }',
    ],
    'assignment for the whole group' => [
        fn (JsonSchemaTypeFactory $schema): Type => AssignmentSchema::make($schema),
        '{ "id": 40, "group_id": 12, "content": { "id": 7, "title": "ECG Basics", "topic": "Cardiology", "type": "article", "estimated_minutes": 15 }, "due_at": "2026-10-02T23:59:59+02:00", "scope": "group", "targeted_count": 3, "learners": null, "created_at": "2026-09-25T10:00:00+02:00" }',
    ],
]);

test('a learner with an email does not match: the schemas allow no extra field', function () {
    $schema = LearnerSchema::make(new JsonSchemaTypeFactory)->toArray();

    expect(schemaErrors($schema, ['id' => 101, 'name' => 'Sara Lindqvist', 'email' => 'sara@example.edu']))
        ->toBe(['$.email is not allowed']);
});

test('a reason of an unknown kind does not match', function () {
    $schema = ReasonSchema::make(new JsonSchemaTypeFactory)->toArray();

    expect(schemaErrors($schema, ['kind' => 'late', 'text' => 'Late']))->toBe(['$ matches no anyOf option']);
});
