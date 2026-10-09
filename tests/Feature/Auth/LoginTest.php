<?php

use App\Models\Institution;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PassportSeeder;

use function Pest\Laravel\postJson;
use function Pest\Laravel\seed;

beforeEach(function () {
    seed(PassportSeeder::class);
});

test('the right password answers 200 with an 8 hour token and the user', function () {
    $institution = Institution::factory()->create(['timezone' => 'Europe/Berlin']);
    $educator = User::factory()->educator()->create(['institution_id' => $institution->id]);

    $response = postJson('/api/v1/auth/login', ['email' => $educator->email, 'password' => 'password']);

    $response->assertOk();

    $data = $response->json('data');
    $expiresAt = CarbonImmutable::parse($data['expires_at']);

    expect(array_keys($data))->toBe(['token', 'token_type', 'expires_at', 'user'])
        ->and($data['token'])->toMatch('/^[\w-]+\.[\w-]+\.[\w-]+$/')
        ->and($data['token_type'])->toBe('Bearer')
        ->and($data['user'])->toBe(['id' => $educator->id, 'name' => $educator->name, 'role' => 'educator'])
        ->and($data['expires_at'])->toEndWith(now('Europe/Berlin')->format('P'))
        ->and($expiresAt->diffInSeconds(now()->addHours(8), absolute: true))->toBeLessThan(60);
});

test('a wrong password answers 401 invalid_credentials', function () {
    $user = User::factory()->create();

    postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer')
        ->assertExactJson([
            'error' => [
                'code' => 'invalid_credentials',
                'message' => 'These credentials do not match our records.',
            ],
        ]);
});

test('an unknown email answers the same status and body as a wrong password', function () {
    $user = User::factory()->create();

    $wrongPassword = postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password']);
    $unknownEmail = postJson('/api/v1/auth/login', ['email' => 'nobody@example.edu', 'password' => 'password']);

    expect($unknownEmail->status())->toBe(401)
        ->and($unknownEmail->getContent())->toBe($wrongPassword->getContent());
});

test('a missing field answers 422 validation_failed with details', function (array $body, string $field) {
    postJson('/api/v1/auth/login', $body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.message', 'The given data was invalid.')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'email' => [['password' => 'password'], 'email'],
    'password' => [['email' => 'anna.keller@example.edu'], 'password'],
]);
