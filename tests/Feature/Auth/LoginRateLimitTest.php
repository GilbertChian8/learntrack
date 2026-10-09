<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PassportSeeder;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\post;
use function Pest\Laravel\postJson;
use function Pest\Laravel\seed;
use function Pest\Laravel\travel;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-10 09:00:00');
    seed(PassportSeeder::class);

    // The 200 ms login timebox is not under test here, and 50 failures would take 10 seconds.
    config(['auth.timebox_duration' => 0]);
});

$login = fn (string $route, string $email, string $password): TestResponse => $route === '/login'
    ? post($route, ['email' => $email, 'password' => $password])
    : postJson($route, ['email' => $email, 'password' => $password]);

dataset('logins', [
    'api' => ['/api/v1/auth/login', 401, 200],
    'web' => ['/login', 302, 302],
]);

test('ten failures for one email and IP, then the eleventh answers 429', function (string $route, int $failed, int $succeeded) use ($login) {
    $user = User::factory()->create();

    foreach (range(1, 10) as $attempt) {
        $login($route, $user->email, 'wrong-password')->assertStatus($failed);
    }

    $login($route, $user->email, 'password')
        ->assertTooManyRequests()
        ->assertHeader('Retry-After', '900');

    $login($route, 'someone.else@example.edu', 'wrong-password')->assertStatus($failed);

    travel(15)->minutes();

    $login($route, $user->email, 'password')->assertStatus($succeeded);
})->with('logins');

test('the API 429 carries the contract body', function () use ($login) {
    $user = User::factory()->create();

    foreach (range(1, 10) as $attempt) {
        $login('/api/v1/auth/login', $user->email, 'wrong-password');
    }

    $login('/api/v1/auth/login', $user->email, 'wrong-password')
        ->assertTooManyRequests()
        ->assertHeader('Retry-After', '900')
        ->assertExactJson([
            'error' => [
                'code' => 'too_many_requests',
                'message' => 'Too many requests.',
                'details' => ['retry_after' => 900],
            ],
        ]);
});

test('a successful login resets the counter', function (string $route, int $failed, int $succeeded) use ($login) {
    $user = User::factory()->create();

    foreach (range(1, 9) as $attempt) {
        $login($route, $user->email, 'wrong-password')->assertStatus($failed);
    }

    $login($route, $user->email, 'password')->assertStatus($succeeded);

    foreach (range(1, 10) as $attempt) {
        $login($route, $user->email, 'wrong-password')->assertStatus($failed);
    }

    $login($route, $user->email, 'password')->assertTooManyRequests();
})->with('logins');

test('successful logins never count', function (string $route, int $failed, int $succeeded) use ($login) {
    $user = User::factory()->create();

    foreach (range(1, 51) as $attempt) {
        $login($route, $user->email, 'password')->assertStatus($succeeded);
    }

    foreach (range(1, 10) as $attempt) {
        $login($route, $user->email, 'wrong-password')->assertStatus($failed);
    }

    $login($route, $user->email, 'password')->assertTooManyRequests();
})->with('logins');

test('fifty failures from one IP across different emails, then the next answers 429', function (string $route, int $failed) use ($login) {
    $user = User::factory()->create();

    foreach (range(1, 50) as $attempt) {
        $login($route, "unknown{$attempt}@example.edu", 'wrong-password')->assertStatus($failed);
    }

    $login($route, $user->email, 'password')
        ->assertTooManyRequests()
        ->assertHeader('Retry-After', '900');
})->with('logins');

test('web and API failures count together', function () use ($login) {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $login('/login', $user->email, 'wrong-password')->assertRedirect();
        $login('/api/v1/auth/login', $user->email, 'wrong-password')->assertUnauthorized();
    }

    $login('/login', $user->email, 'password')->assertTooManyRequests();
    $login('/api/v1/auth/login', $user->email, 'password')->assertTooManyRequests();
});
