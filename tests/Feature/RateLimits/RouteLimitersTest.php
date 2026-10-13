<?php

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

test('each route has the limiter of the TDD table, and the public pages have none', function () {
    $limiters = collect(Route::getRoutes()->getRoutes())
        ->mapWithKeys(fn (RoutingRoute $route) => [
            implode('|', $route->methods()).' '.$route->uri() => collect($route->gatherMiddleware())
                ->filter(fn (mixed $middleware) => is_string($middleware) && Str::startsWith($middleware, 'throttle:'))
                ->values()
                ->all(),
        ])
        ->filter()
        ->sortKeys()
        ->all();

    expect($limiters)->toBe([
        'DELETE api/v1/assignments/{removableAssignment}' => ['throttle:api'],
        'DELETE api/v1/groups/{group}/learners/{user}' => ['throttle:api'],
        'GET|HEAD api/v1/content' => ['throttle:api'],
        'GET|HEAD api/v1/groups' => ['throttle:api'],
        'GET|HEAD api/v1/groups/{group}' => ['throttle:api'],
        'GET|HEAD api/v1/groups/{group}/assignments' => ['throttle:api'],
        'GET|HEAD api/v1/groups/{group}/audit' => ['throttle:api'],
        'GET|HEAD api/v1/learners' => ['throttle:api'],
        'GET|HEAD api/v1/me' => ['throttle:api'],
        'GET|HEAD api/v1/me/assignments' => ['throttle:api'],
        'PATCH api/v1/assignments/{assignment}' => ['throttle:api'],
        'POST api/v1/auth/logout' => ['throttle:api'],
        'POST api/v1/groups' => ['throttle:api'],
        'POST api/v1/groups/{group}/assignments' => ['throttle:api'],
        'POST api/v1/groups/{group}/learners' => ['throttle:api'],
        'POST graphql' => ['throttle:api'],
        'POST mcp' => ['throttle:mcp'],
        'POST oauth/register' => ['throttle:oauth-register'],
        'POST oauth/token' => ['throttle:oauth-token'],
        'PUT api/v1/me/assignments/{learnerAssignment}/progress' => ['throttle:api'],
    ]);
});
