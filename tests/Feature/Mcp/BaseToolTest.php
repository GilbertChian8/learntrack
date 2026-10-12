<?php

use App\Models\Group;
use Laravel\Mcp\Facades\Mcp;
use Tests\Feature\Mcp\Fixtures\FixtureServer;

use function Pest\Laravel\postJson;

beforeEach(function () {
    Mcp::web('mcp-fixture', FixtureServer::class)->middleware(['auth:api', 'role:educator']);
});

test('a result is the structured content plus one text summary, from the same array', function () {
    [$group, $educator] = cardiologyGroup();
    actingAsEducator($educator);

    postJson('/mcp-fixture', mcpRequest('tools/call', ['name' => 'find_group', 'arguments' => ['group_id' => $group->id]]))
        ->assertOk()
        ->assertJsonPath('result.content', [['type' => 'text', 'text' => 'Found Cardiology Group A.']])
        ->assertJsonPath('result.isError', false)
        ->assertJsonPath('result.structuredContent', ['group' => ['id' => $group->id, 'name' => 'Cardiology Group A']]);
});

test('a NotFoundException is the tool error, with its message and nothing else', function (Closure $groupId) {
    [, $educator] = cardiologyGroup();
    actingAsEducator($educator);

    postJson('/mcp-fixture', mcpRequest('tools/call', ['name' => 'find_group', 'arguments' => ['group_id' => $groupId()]]))
        ->assertOk()
        ->assertJsonPath('result.content', [['type' => 'text', 'text' => 'Group not found.']])
        ->assertJsonPath('result.isError', true)
        ->assertJsonMissingPath('result.structuredContent');
})->with([
    'a group the educator does not teach' => fn () => Group::factory()->create()->id,
    'a group that does not exist' => fn () => 999999,
]);
