<?php

use App\Models\Institution;
use App\Models\User;
use Database\Seeders\PassportSeeder;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\seed;

test('the token from login works on me and the body matches the contract', function () {
    seed(PassportSeeder::class);
    $institution = Institution::factory()->create(['name' => 'Northside Medical School', 'timezone' => 'Europe/Berlin']);
    $educator = User::factory()->educator()->create(['institution_id' => $institution->id]);

    $token = postJson('/api/v1/auth/login', ['email' => $educator->email, 'password' => 'password'])->json('data.token');

    getJson('/api/v1/me', ['Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'id' => $educator->id,
                'name' => $educator->name,
                'email' => $educator->email,
                'role' => 'educator',
                'institution' => [
                    'id' => $institution->id,
                    'name' => 'Northside Medical School',
                    'timezone' => 'Europe/Berlin',
                ],
            ],
        ]);
});

test('me serves learners too', function () {
    $learner = actingAsLearner();

    getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.id', $learner->id)
        ->assertJsonPath('data.role', 'learner');
});

test('me without a token answers 401 in the contract shape', function () {
    getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer')
        ->assertExactJson([
            'error' => [
                'code' => 'unauthenticated',
                'message' => 'Unauthenticated.',
            ],
        ]);
});
