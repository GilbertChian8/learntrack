<?php

use App\Models\User;
use Database\Seeders\PassportSeeder;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\seed;

test('logout answers 204 and the same token then answers 401', function () {
    seed(PassportSeeder::class);
    $user = User::factory()->create();

    $token = postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->json('data.token');
    $headers = ['Authorization' => "Bearer {$token}"];

    postJson('/api/v1/auth/logout', [], $headers)->assertNoContent();

    // Guards keep their user between test requests; a real request starts fresh.
    app('auth')->forgetGuards();

    getJson('/api/v1/me', $headers)
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer')
        ->assertExactJson(['error' => ['code' => 'unauthenticated', 'message' => 'Unauthenticated.']]);

    postJson('/api/v1/auth/logout', [], $headers)->assertUnauthorized();
});
