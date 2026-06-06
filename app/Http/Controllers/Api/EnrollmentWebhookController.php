<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EnrollmentWebhookCredential;
use App\Services\EnrollmentWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnrollmentWebhookController extends Controller
{
    /**
     * POST /api/webhooks/enrollment/{webhook_key} — autenticação pela URL (n8n).
     */
    public function byKey(Request $request, string $webhookKey, EnrollmentWebhookService $service): JsonResponse
    {
        $credential = EnrollmentWebhookCredential::findByWebhookKey($webhookKey);
        if ($credential === null) {
            return response()->json(['success' => false, 'message' => 'Webhook não encontrado.'], 404);
        }

        if (! $credential->is_active) {
            return response()->json(['success' => false, 'message' => 'Webhook inativo.'], 403);
        }

        return $this->processEnrollment($request, $service, $credential);
    }

    /**
     * POST /api/webhooks/enrollment — legado Bearer token (CLI / integrações antigas).
     */
    public function __invoke(Request $request, EnrollmentWebhookService $service): JsonResponse
    {
        $credential = $request->attributes->get('enrollment_webhook_credential');
        if (! $credential instanceof EnrollmentWebhookCredential) {
            return response()->json(['success' => false, 'message' => 'Token de autenticação inválido.'], 401);
        }

        return $this->processEnrollment($request, $service, $credential);
    }

    private function processEnrollment(
        Request $request,
        EnrollmentWebhookService $service,
        EnrollmentWebhookCredential $credential
    ): JsonResponse {
        $tenantId = (int) $credential->tenant_id;

        foreach (['course_id', 'hub_id'] as $uuidField) {
            if ($request->has($uuidField) && $request->input($uuidField) !== null) {
                $request->merge([$uuidField => (string) $request->input($uuidField)]);
            }
        }

        $payload = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'document' => ['nullable', 'string', 'max:32'],
            'course_id' => ['nullable', 'string', 'max:36'],
            'hub_id' => ['nullable', 'string', 'max:36'],
            'external_product_id' => ['nullable', 'string', 'max:191'],
            'platform' => ['nullable', 'string', 'max:64'],
            'event' => ['required', 'string', 'max:64'],
            'transaction_id' => ['nullable', 'string', 'max:191'],
            'status' => ['nullable', 'string', 'max:64'],
            'send_access_email' => ['nullable', 'boolean'],
        ]);

        $result = $service->process($tenantId, $payload, $credential);
        $credential->touchLastUsed();

        $status = ($result['success'] ?? false) ? 200 : 422;

        return response()->json($result, $status);
    }
}
