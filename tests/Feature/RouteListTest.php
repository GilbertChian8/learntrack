<?php

use Illuminate\Support\Facades\Artisan;

test('the application serves only its own routes: no Passport management, no starter kit, no file serving', function () {
    Artisan::call('route:list', ['--json' => true]);

    /** @var list<array{method: string, uri: string}> $routes */
    $routes = json_decode(Artisan::output(), true);

    expect(collect($routes)->map(fn (array $route) => $route['method'].' '.$route['uri'])->sort()->values()->all())
        ->toBe([
            'DELETE api/v1/assignments/{removableAssignment}',
            'DELETE api/v1/groups/{group}/learners/{user}',
            'DELETE mcp',
            'DELETE oauth/authorize',
            'GET|HEAD .well-known/oauth-authorization-server',
            'GET|HEAD .well-known/oauth-authorization-server/{path}',
            'GET|HEAD .well-known/oauth-protected-resource',
            'GET|HEAD .well-known/oauth-protected-resource/{path}',
            'GET|HEAD /',
            'GET|HEAD api/v1/content',
            'GET|HEAD api/v1/groups',
            'GET|HEAD api/v1/groups/{group}',
            'GET|HEAD api/v1/groups/{group}/assignments',
            'GET|HEAD api/v1/groups/{group}/audit',
            'GET|HEAD api/v1/learners',
            'GET|HEAD api/v1/me',
            'GET|HEAD login',
            'GET|HEAD mcp',
            'GET|HEAD oauth/authorize',
            'GET|HEAD up',
            'PATCH api/v1/assignments/{assignment}',
            'POST api/v1/auth/login',
            'POST api/v1/auth/logout',
            'POST api/v1/groups',
            'POST api/v1/groups/{group}/assignments',
            'POST api/v1/groups/{group}/learners',
            'POST graphql',
            'POST login',
            'POST logout',
            'POST mcp',
            'POST oauth/authorize',
            'POST oauth/register',
            'POST oauth/token',
        ]);
});
