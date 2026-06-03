<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        // CSP só em produção — no local o Vite usa localhost:5173 e seria bloqueado
        if (config('app.env') === 'production') {
            $response->headers->set('Content-Security-Policy', $this->buildContentSecurityPolicy());
        }

        if (config('app.env') === 'production' && $request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        return $response;
    }

    private function buildContentSecurityPolicy(): string
    {
        $connectSrc = $this->mergeDirectiveSources(
            config('csp.connect_src', []),
            array_merge(
                $this->cspExtraConnectSrcForStorage(),
                $this->parseCsvOrigins((string) config('csp.extra_connect_src', ''))
            )
        );

        $directives = [
            "default-src 'self'",
            'script-src '.$this->mergeDirectiveSources(
                config('csp.script_src', []),
                $this->parseCsvOrigins((string) config('csp.extra_script_src', ''))
            ),
            'script-src-elem '.$this->mergeDirectiveSources(
                config('csp.script_src_elem', config('csp.script_src', [])),
                $this->parseCsvOrigins((string) config('csp.extra_script_src', ''))
            ),
            'style-src '.$this->mergeDirectiveSources(config('csp.style_src', [])),
            'img-src '.$this->mergeDirectiveSources(config('csp.img_src', [])),
            'font-src '.$this->mergeDirectiveSources(config('csp.font_src', [])),
            'connect-src '.$connectSrc,
            'frame-src '.$this->mergeDirectiveSources(
                config('csp.frame_src', []),
                $this->parseCsvOrigins((string) config('csp.extra_frame_src', ''))
            ),
            'media-src '.$this->mergeDirectiveSources(config('csp.media_src', [])),
            'worker-src '.$this->mergeDirectiveSources(config('csp.worker_src', [])),
        ];

        return implode('; ', $directives);
    }

    /**
     * @param  array<int, string>  $base
     * @param  array<int, string>  $extra
     */
    private function mergeDirectiveSources(array $base, array $extra = []): string
    {
        $sources = array_values(array_unique(array_filter(array_merge($base, $extra), fn ($v) => is_string($v) && $v !== '')));

        return implode(' ', $sources);
    }

    /**
     * @return array<int, string>
     */
    private function parseCsvOrigins(string $csv): array
    {
        if (trim($csv) === '') {
            return [];
        }

        $origins = [];
        foreach (explode(',', $csv) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $origins[] = $part;
            }
        }

        return $origins;
    }

    /**
     * @return array<int, string>
     */
    private function cspExtraConnectSrcForStorage(): array
    {
        $origins = [];

        if (! config('csp.disable_getfy_r2_origin', false)) {
            $origins[] = 'https://r2.getfy.cloud';
        }

        $awsUrl = (string) config('filesystems.disks.s3.url', '');
        if ($awsUrl === '') {
            $awsUrl = (string) env('AWS_URL', '');
        }
        if ($awsUrl !== '' && str_starts_with($awsUrl, 'http')) {
            $parsed = parse_url($awsUrl);
            $scheme = $parsed['scheme'] ?? '';
            $host = $parsed['host'] ?? '';
            if (($scheme === 'https' || $scheme === 'http') && $host !== '') {
                $origins[] = $scheme.'://'.$host;
            }
        }

        return array_values(array_unique($origins));
    }
}
