<?php

namespace App\Mcp\Tools;

use App\Models\Group;
use App\Queries\MyGroups;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_my_groups')]
#[Title('My groups')]
#[Description('Lists the groups you teach, with the number of learners and active assignments in each. Call this first to get the group ids the other tools need.')]
#[IsReadOnly]
#[IsIdempotent]
class ListMyGroups extends BaseTool
{
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'groups' => $schema->array()->items($schema->object([
                'id' => $schema->integer()->required(),
                'name' => $schema->string()->required(),
                'institution' => $schema->string()->required(),
                'learner_count' => $schema->integer()->min(0)->required(),
                'assignment_count' => $schema->integer()->min(0)->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }

    protected function answer(Request $request): array
    {
        return [
            'groups' => MyGroups::for($this->educator($request))->get()->map(fn (Group $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'institution' => $group->institution->name,
                'learner_count' => $group->getAttribute('learner_count'),
                'assignment_count' => $group->getAttribute('assignment_count'),
            ])->all(),
        ];
    }
}
