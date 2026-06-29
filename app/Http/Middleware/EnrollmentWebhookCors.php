<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CORS permissivo para POST /api/webhooks/enrollment.
 * Segurança via Bearer token (+ HMAC opcional), não por origem.
 */
class EnrollmentWebhookCors
{
    private const ALLOW_ORIGIN = '*';

    private const ALLOW_METHODS = 'GET, HEAD, POST, OPTIONS';

    private const ALLOW_HEADERS = 'Authorization, Content-Type, X-Signature, Accept, Origin, X-Requested-With';

    private const MAX_AGE = '86400';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->withCorsHeaders(response('', 204));
        }

        return $this->withCorsHeaders($next($request));
    }

    private function withCorsHeaders(Response $response): Response
    {
        if (! $response->headers->has('Access-Control-Allow-Origin')) {
            $response->headers->set('Access-Control-Allow-Origin', self::ALLOW_ORIGIN);
        }

        if (! $response->headers->has('Access-Control-Allow-Methods')) {
            $response->headers->set('Access-Control-Allow-Methods', self::ALLOW_METHODS);
        }

        if (! $response->headers->has('Access-Control-Allow-Headers')) {
            $response->headers->set('Access-Control-Allow-Headers', self::ALLOW_HEADERS);
        }

        if (! $response->headers->has('Access-Control-Max-Age')) {
            $response->headers->set('Access-Control-Max-Age', self::MAX_AGE);
        }

        $response->headers->set('Vary', 'Origin, Access-Control-Request-Method, Access-Control-Request-Headers', false);

        return $response;
    }
}
