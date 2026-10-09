<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

test('guests are redirected to the login page', function () {
    get('/')->assertRedirect('/login');
});

test('signed in users are redirected to the login page, which renders', function () {
    actingAs(User::factory()->create())
        ->followingRedirects()
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/login'));
});
