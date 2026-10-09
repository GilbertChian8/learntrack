<?php

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Models\User;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

test('an unknown /api/v1 route answers 404 in the contract shape', function () {
    getJson('/api/v1/no-such-route')
        ->assertNotFound()
        ->assertExactJson(['error' => ['code' => 'not_found', 'message' => 'Not found.']]);
});

test('a known /api/v1 path with the wrong method answers 404', function () {
    getJson('/api/v1/auth/login')
        ->assertNotFound()
        ->assertExactJson(['error' => ['code' => 'not_found', 'message' => 'Not found.']]);
});

test('every not-found exception renders the same 404 body', function (Closure $throw) {
    Route::middleware('api')->get('api/v1/test/not-found', $throw);

    getJson('/api/v1/test/not-found')
        ->assertNotFound()
        ->assertExactJson(['error' => ['code' => 'not_found', 'message' => 'Not found.']]);
})->with([
    'NotFoundException' => fn () => throw NotFoundException::group(),
    'ModelNotFoundException' => fn () => User::query()->findOrFail(0),
    'NotFoundHttpException' => fn () => abort(404),
]);

test('a ValidationException renders 422 validation_failed with details', function () {
    Route::middleware('api')->post('api/v1/test/validate', fn (Request $request) => $request->validate([
        'learner_ids' => ['required', 'array'],
        'learner_ids.*' => ['integer'],
    ]));

    postJson('/api/v1/test/validate', ['learner_ids' => [3, 'x']])
        ->assertUnprocessable()
        ->assertExactJson([
            'error' => [
                'code' => 'validation_failed',
                'message' => 'The given data was invalid.',
                'details' => ['learner_ids.1' => ['The learner_ids.1 field must be an integer.']],
            ],
        ]);
});

test('a ThrottleRequestsException renders 429 with Retry-After and details.retry_after', function () {
    Route::middleware('api')->get('api/v1/test/throttled', fn () => throw new ThrottleRequestsException(headers: ['Retry-After' => 42]));

    getJson('/api/v1/test/throttled')
        ->assertTooManyRequests()
        ->assertHeader('Retry-After', '42')
        ->assertExactJson([
            'error' => [
                'code' => 'too_many_requests',
                'message' => 'Too many requests.',
                'details' => ['retry_after' => 42],
            ],
        ]);
});

test('a ConflictException renders 409 with its code and details', function () {
    $conflict = new class('The content is already assigned with another due date.') extends ConflictException
    {
        public function code(): string
        {
            return 'already_assigned';
        }

        public function details(): array
        {
            return ['assignment' => ['id' => 41]];
        }
    };

    Route::middleware('api')->post('api/v1/test/conflict', fn () => throw $conflict);

    postJson('/api/v1/test/conflict')
        ->assertConflict()
        ->assertExactJson([
            'error' => [
                'code' => 'already_assigned',
                'message' => 'The content is already assigned with another due date.',
                'details' => ['assignment' => ['id' => 41]],
            ],
        ]);
});

test('an unexpected exception renders 500 server_error with no internals', function () {
    Exceptions::fake();
    Route::middleware('api')->get('api/v1/test/boom', fn () => throw new RuntimeException('SQLSTATE secret at /var/www/html'));

    $response = getJson('/api/v1/test/boom');

    $response->assertInternalServerError()
        ->assertExactJson(['error' => ['code' => 'server_error', 'message' => 'Server error.']]);

    expect($response->getContent())->not->toContain('SQLSTATE');

    Exceptions::assertReported(RuntimeException::class);
});

test('graphql and mcp requests get the contract shape too', function (string $path) {
    Route::middleware(['api', 'auth:api'])->post($path, fn () => response()->noContent());

    postJson($path)
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer')
        ->assertExactJson(['error' => ['code' => 'unauthenticated', 'message' => 'Unauthenticated.']]);
})->with(['/graphql', '/mcp']);

test('web requests keep the default rendering', function () {
    $response = get('/no-such-page');

    $response->assertNotFound();

    expect($response->headers->get('Content-Type'))->toContain('text/html');
});
