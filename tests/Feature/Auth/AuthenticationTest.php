<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

test('login screen can be rendered', function () {
    get('/login')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/login'));
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect('/');

    assertAuthenticatedAs($user);
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertSessionHasErrors('email');

    assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    actingAs($user)->post('/logout')->assertRedirect('/');

    assertGuest();
});
