<?php

use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

test('the application registers only home, the login routes and the API routes built so far', function () {
    Artisan::call('route:list', ['--except-vendor' => true, '--json' => true]);

    /** @var list<array{method: string, uri: string}> $routes */
    $routes = json_decode(Artisan::output(), true);

    // /up is registered by the framework, so --except-vendor hides it; HealthTest covers it.
    expect(collect($routes)->map(fn (array $route) => $route['method'].' '.$route['uri'])->sort()->values()->all())
        ->toBe([
            'DELETE api/v1/assignments/{removableAssignment}',
            'DELETE api/v1/groups/{group}/learners/{user}',
            'GET|HEAD /',
            'GET|HEAD api/v1/content',
            'GET|HEAD api/v1/groups',
            'GET|HEAD api/v1/groups/{group}',
            'GET|HEAD api/v1/groups/{group}/assignments',
            'GET|HEAD api/v1/groups/{group}/audit',
            'GET|HEAD api/v1/learners',
            'GET|HEAD api/v1/me',
            'GET|HEAD api/v1/me/assignments',
            'GET|HEAD login',
            'PATCH api/v1/assignments/{assignment}',
            'POST api/v1/auth/login',
            'POST api/v1/auth/logout',
            'POST api/v1/groups',
            'POST api/v1/groups/{group}/assignments',
            'POST api/v1/groups/{group}/learners',
            'POST login',
            'POST logout',
            'PUT api/v1/me/assignments/{learnerAssignment}/progress',
        ]);
});

test('Passport registers no routes of its own', function () {
    $oauthRoutes = collect(Route::getRoutes()->getRoutes())
        ->map(fn (RouteDefinition $route) => $route->uri())
        ->filter(fn (string $uri) => str_starts_with($uri, 'oauth'));

    expect($oauthRoutes->all())->toBe([]);
});
