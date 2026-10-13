<?php

namespace App\Mcp\Tools;

use App\Mcp\Schemas\AssignmentStatsSchema;
use App\Mcp\Schemas\CountsSchema;
use App\Mcp\Schemas\ReasonSchema;
use App\Queries\GroupProgress;
use App\Queries\Results\AssignmentStats;
use App\Queries\Results\LearnerProgress;
use App\Queries\Results\Reason;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_group_progress')]
#[Title('Group progress')]
#[Description('Shows one group\'s progress: per learner (status counts, average question set score, whether they are behind and why) and per assignment (how many completed it, completion rate, average score). Use find_learners_behind when you only need the learners who are behind.')]
#[IsReadOnly]
#[IsIdempotent]
class GetGroupProgress extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'group_id' => $schema->integer()->description('The group id from list_my_groups.')->required(),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'group' => $schema->object([
                'id' => $schema->integer()->required(),
                'name' => $schema->string()->required(),
                'institution' => $schema->string()->required(),
            ])->withoutAdditionalProperties()->required(),
            'generated_at' => $schema->string()->format('date-time')->required(),
            'learner_count' => $schema->integer()->min(0)->required(),
            'behind_count' => $schema->integer()->min(0)->required(),
            'learners' => $schema->array()->items($schema->object([
                'id' => $schema->integer()->required(),
                'name' => $schema->string()->required(),
                'behind' => $schema->boolean()->required(),
                'reasons' => $schema->array()->items(ReasonSchema::make($schema))->required(),
                'average_score' => $schema->number()->nullable()->required(),
                'counts' => CountsSchema::make($schema)->required(),
            ])->withoutAdditionalProperties())->required(),
            'assignments' => $schema->array()->items(AssignmentStatsSchema::make($schema))->required(),
        ];
    }

    protected function answer(Request $request): array
    {
        $request->validate(['group_id' => ['required', 'integer:strict']]);
        $progress = GroupProgress::for($this->educator($request), $request->integer('group_id'));

        return [
            'group' => ['id' => $progress->groupId, 'name' => $progress->groupName, 'institution' => $progress->institutionName],
            'generated_at' => $progress->generatedAt->toIso8601String(),
            'learner_count' => $progress->learnerCount,
            'behind_count' => $progress->behindCount,
            'learners' => array_map(fn (LearnerProgress $learner) => [
                'id' => $learner->learnerId,
                'name' => $learner->learnerName,
                'behind' => $learner->behind,
                'reasons' => array_map(fn (Reason $reason) => $reason->toArray(), $learner->reasons),
                'average_score' => $learner->averageScore,
                'counts' => CountsSchema::from($learner->counts),
            ], $progress->learners),
            'assignments' => array_map(fn (AssignmentStats $assignment) => AssignmentStatsSchema::from($assignment), $progress->assignments),
        ];
    }
}
