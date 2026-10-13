<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Http\Response as ViewResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The security headers of every response, and the policy of the three pages (app.blade.php).
 * The page's inline script and styles carry the policy's nonce. In local, the policy also allows the running Vite dev server.
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
        ]);

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if ($this->rendersAppView($response)) {
            $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy($nonce));
        }

        return $response;
    }

    private function rendersAppView(Response $response): bool
    {
        $content = $response instanceof ViewResponse ? $response->getOriginalContent() : null;

        return $content instanceof View && $content->name() === 'app';
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
