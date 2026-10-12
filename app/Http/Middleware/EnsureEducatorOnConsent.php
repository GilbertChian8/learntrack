<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only educators reach the consent step, so a learner never gets an authorization code.
 */
class EnsureEducatorOnConsent
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role === Role::Educator) {
            return $next($request);
        }

        return Inertia::render('mcp/message', [
            'title' => 'Educators only',
            'message' => 'Only educators can connect an AI assistant.',
            'signOut' => true,
        ])->toResponse($request)->setStatusCode(403);
    }
}
