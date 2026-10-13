<?php

namespace App\Mcp\Tools;

use App\Mcp\Schemas\AssignmentStatsSchema;
use App\Queries\HardestAssignments;
use App\Queries\Results\RankedAssignment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('find_hardest_assignments')]
#[Title('Hardest assignments')]
#[Description('Ranks a group\'s assignments by difficulty: lowest completion rate first, then lowest average score. Each result says how many learners completed it and the average score, so you can explain why it ranks there.')]
#[IsReadOnly]
#[IsIdempotent]
class FindHardestAssignments extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'group_id' => $schema->integer()->description('The group id from list_my_groups.')->required(),
            'limit' => $schema->integer()->min(1)->max(20)->default(5)->description('How many assignments to return. Default 5.'),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'group' => $schema->object([
                'id' => $schema->integer()->required(),
                'name' => $schema->string()->required(),
            ])->withoutAdditionalProperties()->required(),
            'assignments' => $schema->array()->items(AssignmentStatsSchema::make($schema, [
                'reason' => $schema->string()->required(),
            ]))->required(),
        ];
    }

    protected function answer(Request $request): array
    {
        $request->validate([
            'group_id' => ['required', 'integer:strict'],
            'limit' => ['integer:strict', 'min:1', 'max:20'],
        ]);
        $hardest = HardestAssignments::for($this->educator($request), $request->integer('group_id'), $request->integer('limit', 5));

        return [
            'group' => ['id' => $hardest->groupId, 'name' => $hardest->groupName],
            'assignments' => array_map(fn (RankedAssignment $ranked) => [
                ...AssignmentStatsSchema::from($ranked->stats),
                'reason' => $ranked->reason,
            ], $hardest->assignments),
        ];
    }
}
