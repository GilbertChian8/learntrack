<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The security headers of every response. The page's inline script and styles carry the nonce of the policy.
 * In local, the policy also allows the Vite dev server while it runs.
 */
class SecurityHeaders
{
    public function __construct(private Vite $vite) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = $this->vite->useCspNonce();

        $response = $next($request);

        $response->headers->add([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Content-Security-Policy' => $this->contentSecurityPolicy($nonce),
        ]);

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function contentSecurityPolicy(string $nonce): string
    {
        $devServer = app()->isLocal() ? $this->vite->devServerUrl() : null;
        $sources = $devServer === null ? "'self'" : "'self' {$devServer}";

        return implode('; ', array_filter([
            "default-src {$sources}",
            "script-src {$sources} 'nonce-{$nonce}'",
            "style-src {$sources} 'nonce-{$nonce}'",
            $devServer === null ? null : "connect-src {$sources} ".preg_replace('/^http/', 'ws', $devServer),
            "object-src 'none'",
            "base-uri 'self'",
            "frame-ancestors 'none'",
        ]));
    }
}
