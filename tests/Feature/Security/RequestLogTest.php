<?php

use App\Models\User;
use Database\Seeders\PassportSeeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\seed;

/**
 * @param  list<array<string, mixed>>  $lines
 * @return list<array<string, mixed>>
 */
function requestLines(array $lines): array
{
    return array_values(array_filter($lines, fn (array $line) => $line['message'] === 'Request handled.'));
}

test('a request line has the request id, the user id, the route and the status, never the body or the Authorization header', function () {
    seed(PassportSeeder::class);
    $user = User::factory()->educator()->create(['email' => 'anna.keller@example.edu', 'password' => 'tr0ub4dor-and-3']);
    $lines = captureLogLines();

    $token = postJson('/api/v1/auth/login', ['email' => 'anna.keller@example.edu', 'password' => 'tr0ub4dor-and-3'])->assertOk()->json('data.token');
    getJson('/api/v1/me', ['Authorization' => "Bearer {$token}"])->assertOk();

    [$login, $me] = requestLines($lines());

    expect($login['context'])->toMatchArray(['method' => 'POST', 'route' => 'api/v1/auth/login', 'status' => 200, 'user_id' => $user->id])
        ->and($me['context'])->toMatchArray(['method' => 'GET', 'route' => 'api/v1/me', 'status' => 200, 'user_id' => $user->id])
        ->and($me['context']['duration_ms'])->toBeInt()->toBeGreaterThanOrEqual(0)
        ->and($login['extra']['request_id'])->toBeUuid()
        ->and($me['extra']['request_id'])->toBeUuid()
        ->and($me['extra']['request_id'] === $login['extra']['request_id'])->toBeFalse();

    $everything = (string) json_encode($lines());

    expect(array_filter(['anna.keller', 'tr0ub4dor', $token, 'Bearer'], fn (string $secret) => str_contains($everything, $secret)))->toBe([]);
});

test('a named route logs its name, an unknown path no route, and a guest no user id', function () {
    $lines = captureLogLines();

    get('/login')->assertOk();
    getJson('/api/v1/no-such-route')->assertNotFound();

    [$page, $missing] = requestLines($lines());

    expect($page['context'])->toMatchArray(['method' => 'GET', 'route' => 'login', 'status' => 200])
        ->and($missing['context'])->toMatchArray(['method' => 'GET', 'route' => null, 'status' => 404])
        ->and($page['context'])->not->toHaveKey('user_id')
        ->and($missing['context'])->not->toHaveKey('user_id');
});

test('every line written during a request carries its request id', function () {
    Route::middleware('api')->get('api/v1/test/log', function () {
        Log::info('Inside the request.');

        return response()->noContent();
    });
    $lines = captureLogLines();

    getJson('/api/v1/test/log')->assertNoContent();
    getJson('/api/v1/test/log')->assertNoContent();

    $requestIds = collect($lines())->map(fn (array $line) => $line['message'].' '.$line['extra']['request_id']);

    [$first, $second] = collect($lines())->pluck('extra.request_id')->unique()->values()->all();

    expect($requestIds->all())->toBe([
        "Inside the request. {$first}",
        "Request handled. {$first}",
        "Inside the request. {$second}",
        "Request handled. {$second}",
    ]);
});
