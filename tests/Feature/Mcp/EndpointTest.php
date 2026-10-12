<?php

use Illuminate\Support\Facades\DB;

use function Pest\Laravel\json;
use function Pest\Laravel\postJson;

test('a call without a token answers 401 and points at the resource metadata', function () {
    postJson('/mcp', mcpRequest('initialize'))
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp"')
        ->assertExactJson(['error' => ['code' => 'unauthenticated', 'message' => 'Unauthenticated.']]);
});

test('a forged Host header does not change the resource metadata URL', function () {
    postJson('http://evil.example/mcp', mcpRequest('initialize'))
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp"');
});

test('a bad token answers 401 with the same header', function () {
    postJson('/mcp', mcpRequest('initialize'), ['Authorization' => 'Bearer not-a-token'])
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp"');
});

test('a learner token answers 403 forbidden in the contract shape', function () {
    actingAsLearner();

    postJson('/mcp', mcpRequest('initialize'))
        ->assertForbidden()
        ->assertExactJson(['error' => ['code' => 'forbidden', 'message' => 'Forbidden.']]);
});

test('an educator initializes and gets the server name, version and instructions', function () {
    actingAsEducator();

    $result = postJson('/mcp', mcpRequest('initialize', [
        'protocolVersion' => '2025-06-18',
        'capabilities' => (object) [],
        'clientInfo' => ['name' => 'test', 'version' => '1.0.0'],
    ]))->assertOk()->json('result');

    expect($result)->toMatchArray([
        'protocolVersion' => '2025-06-18',
        'capabilities' => ['tools' => ['listChanged' => false]],
        'serverInfo' => ['name' => 'LearnTrack Educator', 'version' => '1.0.0'],
        'instructions' => 'LearnTrack shows how the learners in the groups you teach are doing. Start with list_my_groups to get group ids. Tools answer only about your own groups; anything else is "not found". Every answer carries the reasons behind it, so quote them instead of guessing. assign_content and change_due_date change data: tell the educator exactly what will change and ask before calling them. Dates are given in the institution\'s time zone.',
    ]);
});

test('tools/list returns an empty list', function () {
    actingAsEducator();

    postJson('/mcp', mcpRequest('tools/list'))
        ->assertOk()
        ->assertJsonPath('result.tools', []);
});

test('the endpoint keeps no session: no cookie and no session row', function () {
    actingAsEducator();

    postJson('/mcp', mcpRequest('tools/list'))
        ->assertOk()
        ->assertCookieMissing(config('session.cookie'))
        ->assertHeaderMissing('Set-Cookie');

    expect(DB::table('sessions')->count())->toBe(0);
});

test('GET and DELETE /mcp answer 405 with an empty body, since the server opens no event stream', function (string $method) {
    $response = json($method, '/mcp')->assertMethodNotAllowed()->assertHeader('Allow', 'POST');

    expect($response->getContent())->toBe('');
})->with(['GET', 'DELETE']);
