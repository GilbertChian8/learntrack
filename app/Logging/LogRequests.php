<?php

namespace App\Logging;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every log line of a request its request id, and writes one line per request.
 * The line names the route, never the path or the query, and holds no header or body.
 */
class LogRequests
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Context::add('request_id', (string) Str::uuid());

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $route = $request->route();
        $startedAt = (float) $request->server('REQUEST_TIME_FLOAT', microtime(true));

        $line = [
            'method' => $request->method(),
            'route' => $route instanceof Route ? ($route->getName() ?? $route->uri()) : null,
            'status' => $response->getStatusCode(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];

        if (Auth::hasUser()) {
            $line['user_id'] = Auth::id();
        }

        Log::info('Request handled.', $line);
    }
}
