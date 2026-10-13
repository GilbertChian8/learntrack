<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Passport\Exceptions\InvalidAuthTokenException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A consent answer after the request was answered or replaced. The educator sees the message page, never Passport's text.
 */
class ConsentRequestClosedException extends RuntimeException implements ShouldntReport
{
    public function __construct(InvalidAuthTokenException $previous)
    {
        parent::__construct('The consent request was already answered or replaced.', 0, $previous);
    }

    public function render(Request $request): Response
    {
        return Inertia::render('mcp/message', [
            'title' => 'Request no longer open',
            'message' => 'This request has expired or was already answered. Start the connection again from your AI assistant.',
            'signOut' => false,
        ])->toResponse($request)->setStatusCode(403);
    }
}
