<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PERFORMANCE — compress API payloads.
 *
 * Most web servers (Nginx/Apache) already gzip responses when
 * configured to, but local `php artisan serve` / some shared hosts
 * don't, and it's easy for that server-level setting to quietly go
 * missing during a deploy. This middleware guarantees JSON responses
 * over a minimum size are gzip-compressed at the application layer
 * regardless of server config, for any client that says it accepts
 * gzip (every browser and axios do, by default).
 *
 * This intentionally does NOT touch Inertia page responses (which
 * are also JSON, but Inertia's own X-Inertia header handling and
 * asset pipeline are a separate concern) — only plain
 * response()->json(...) API-style endpoints (recommendations,
 * previews, reports exports, etc.), identified by not carrying the
 * X-Inertia header.
 *
 * Register in bootstrap/app.php:
 *   $middleware->append(\App\Http\Middleware\CompressApiPayload::class);
 */
class CompressApiPayload
{
    /** Don't bother compressing tiny payloads — gzip overhead isn't worth it below this. */
    private const MIN_BYTES_TO_COMPRESS = 860;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldCompress($request, $response)) {
            return $response;
        }

        $content = $response->getContent();

        if ($content === false || strlen($content) < self::MIN_BYTES_TO_COMPRESS) {
            return $response;
        }

        $compressed = gzencode($content, 6);

        if ($compressed === false) {
            return $response;
        }

        $response->setContent($compressed);
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Content-Length', (string) strlen($compressed));
        $response->headers->set('Vary', 'Accept-Encoding');

        return $response;
    }

    private function shouldCompress(Request $request, Response $response): bool
    {
        if (! str_contains((string) $request->header('Accept-Encoding'), 'gzip')) {
            return false;
        }

        if ($response->headers->has('Content-Encoding')) {
            return false; // already compressed upstream
        }

        // Skip Inertia page visits — only compress plain JSON/API
        // responses (recommendations, previews, report data, etc.).
        if ($request->header('X-Inertia')) {
            return false;
        }

        $contentType = (string) $response->headers->get('Content-Type');

        return str_contains($contentType, 'application/json') || str_contains($contentType, 'text/');
    }
}