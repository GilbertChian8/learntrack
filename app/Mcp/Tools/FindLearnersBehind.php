<?php

namespace App\Mcp\Tools;

use App\Mcp\Schemas\ReasonSchema;
use App\Queries\BehindRule;
use App\Queries\GroupProgress;
use App\Queries\Results\LearnerProgress;
use App\Queries\Results\Reason;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('find_learners_behind')]
#[Title('Learners behind')]
#[Description('Lists the learners in a group who are behind, with the reason for each: which assignments are overdue and by how many days, or which question set scores pull their average below 50. An empty list means nobody is behind.')]
#[IsReadOnly]
#[IsIdempotent]
class FindLearnersBehind extends BaseTool
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
            ])->withoutAdditionalProperties()->required(),
            'rule' => $schema->string()->required(),
            'threshold' => $schema->integer()->required(),
            'learner_count' => $schema->integer()->min(0)->required(),
            'behind_count' => $schema->integer()->min(0)->required(),
            'learners' => $schema->array()->items($schema->object([
                'id' => $schema->integer()->required(),
                'name' => $schema->string()->required(),
                'reasons' => $schema->array()->items(ReasonSchema::make($schema))->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }

    protected function answer(Request $request): array
    {
        $request->validate(['group_id' => ['required', 'integer:strict']]);
        $progress = GroupProgress::for($this->educator($request), $request->integer('group_id'));
        $behind = array_values(array_filter($progress->learners, fn (LearnerProgress $learner) => $learner->behind));

        return [
            'group' => ['id' => $progress->groupId, 'name' => $progress->groupName],
            'rule' => BehindRule::ruleText(),
            'threshold' => BehindRule::SCORE_THRESHOLD,
            'learner_count' => $progress->learnerCount,
            'behind_count' => count($behind),
            'learners' => array_map(fn (LearnerProgress $learner) => [
                'id' => $learner->learnerId,
                'name' => $learner->learnerName,
                'reasons' => array_map(fn (Reason $reason) => $reason->toArray(), $learner->reasons),
            ], $behind),
        ];
    }
}
