<?php

namespace App\Http\Middleware;

use App\Models\EnrollmentWebhookCredential;
use App\Services\EnrollmentWebhookAuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateEnrollmentWebhook
{
    public function __construct(
        protected EnrollmentWebhookAuthService $authService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $next($request);
        }

        $plainToken = $this->authService->resolveBearerToken($request);
        if ($plainToken === null || $plainToken === '') {
            return response()->json(['success' => false, 'message' => 'Token de autenticação ausente ou inválido.'], 401);
        }

        $credential = EnrollmentWebhookCredential::findByPlainToken($plainToken);
        if ($credential === null) {
            return response()->json(['success' => false, 'message' => 'Token de autenticação inválido.'], 401);
        }

        if (! $this->authService->verifySignatureIfConfigured($request, $credential)) {
            return response()->json(['success' => false, 'message' => 'Assinatura HMAC inválida.'], 401);
        }

        $request->attributes->set('enrollment_webhook_credential', $credential);
        $request->attributes->set('enrollment_webhook_tenant_id', $credential->tenant_id);

        return $next($request);
    }
}
