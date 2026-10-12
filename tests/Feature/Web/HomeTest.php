<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

test('a guest is redirected to the login page', function () {
    get('/')->assertRedirect('/login');
});

test('a signed in user sees the notice with their name and a sign-out button', function () {
    $user = User::factory()->educator()->create(['name' => 'Anna Keller', 'email' => 'anna.keller@example.edu']);

    $response = actingAs($user)->get('/');

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('mcp/message')
        ->where('title', 'You are signed in')
        ->where('message', 'You are signed in as Anna Keller. Return to your AI assistant to finish connecting.')
        ->where('signOut', true));

    expect(json_encode($response->inertiaPage()))->not->toContain('anna.keller@example.edu')
        ->and(json_encode($response->inertiaPage()))->not->toContain('email');
});
