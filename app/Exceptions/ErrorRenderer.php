<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders api-contract.md's error shape for REST, GraphQL and MCP requests.
 * Web requests return null and keep the default rendering.
 */
class ErrorRenderer
{
    public function __invoke(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*', 'graphql', 'mcp') || $exception instanceof HttpResponseException) {
            return null;
        }

        return match (true) {
            $exception instanceof AuthenticationException => $this->unauthenticated('unauthenticated', 'Unauthenticated.'),
            $exception instanceof InvalidCredentialsException => $this->unauthenticated('invalid_credentials', 'These credentials do not match our records.'),
            $exception instanceof ValidationException => $this->error(422, 'validation_failed', 'The given data was invalid.', $exception->errors()),
            $exception instanceof ConflictException => $this->error(409, $exception->code(), $exception->getMessage(), $exception->details()),
            $exception instanceof NotFoundException => $this->notFound(),
            $exception instanceof HttpExceptionInterface => $this->httpError($exception),
            default => $this->error(500, 'server_error', 'Server error.'),
        };
    }

    private function httpError(HttpExceptionInterface $exception): JsonResponse
    {
        $headers = $exception->getHeaders();

        return match ($status = $exception->getStatusCode()) {
            403 => $this->error(403, 'forbidden', 'Forbidden.'),
            404 => $this->notFound(),
            405 => $this->error(405, 'method_not_allowed', 'Method not allowed.', headers: $headers),
            429 => $this->error(429, 'too_many_requests', 'Too many requests.', ['retry_after' => (int) ($headers['Retry-After'] ?? 0)], $headers),
            default => $this->reasonPhraseError($status, $headers),
        };
    }

    /**
     * Any other HTTP error keeps its status, with its reason phrase as the message and in snake_case as the code.
     *
     * @param  array<string, mixed>  $headers
     */
    private function reasonPhraseError(int $status, array $headers): JsonResponse
    {
        $phrase = Response::$statusTexts[$status] ?? 'Error';
        $code = Str::of($phrase)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();

        return $this->error($status, $code, $phrase, headers: $headers);
    }

    private function unauthenticated(string $code, string $message): JsonResponse
    {
        return $this->error(401, $code, $message, headers: ['WWW-Authenticate' => 'Bearer']);
    }

    private function notFound(): JsonResponse
    {
        return $this->error(404, 'not_found', 'Not found.');
    }

    /**
     * @param  array<string, mixed>|null  $details
     * @param  array<string, mixed>  $headers
     */
    private function error(int $status, string $code, string $message, ?array $details = null, array $headers = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];

        if ($details !== null) {
            $error['details'] = $details;
        }

        return response()->json(['error' => $error], $status, $headers);
    }
}
