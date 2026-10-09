<?php

use Illuminate\Support\Facades\Artisan;

test('the application registers only home and the login routes', function () {
    Artisan::call('route:list', ['--except-vendor' => true, '--json' => true]);

    /** @var list<array{method: string, uri: string}> $routes */
    $routes = json_decode(Artisan::output(), true);

    // /up is registered by the framework, so --except-vendor hides it; HealthTest covers it.
    expect(collect($routes)->map(fn (array $route) => $route['method'].' '.$route['uri'])->sort()->values()->all())
        ->toBe([
            'GET|HEAD /',
            'GET|HEAD login',
            'POST login',
            'POST logout',
        ]);
});
