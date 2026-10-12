<?php

namespace Tests\Feature\Mcp\Fixtures;

use App\Mcp\Tools\BaseTool;
use App\Support\Scope;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('find_group')]
#[Description('Finds one of your groups by id.')]
class FindGroupTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'group_id' => $schema->integer()->description('The group id.')->required(),
        ];
    }

    protected function answer(Request $request): array
    {
        $group = Scope::group($this->educator($request), (int) $request->get('group_id'));

        return ['group' => ['id' => $group->id, 'name' => $group->name]];
    }

    protected function summary(array $answer, Request $request): string
    {
        return "Found {$answer['group']['name']}.";
    }
}
