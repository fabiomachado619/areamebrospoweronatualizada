<?php

namespace App\Http\Controllers;

use App\Services\EnrollmentWebhookAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnrollmentWebhookAdminController extends Controller
{
    public function __construct(
        protected EnrollmentWebhookAdminService $adminService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $tenantId = (int) auth()->user()->tenant_id;

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'product_id' => ['required', 'exists:products,id'],
            'platform' => ['nullable', 'string', 'max:64'],
            'external_product_id' => ['nullable', 'string', 'max:191'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['product_id'] = (string) $validated['product_id'];

        $result = $this->adminService->createWebhook($tenantId, $validated);

        return response()->json([
            'message' => 'Webhook criado.',
            'webhook' => $result['webhook'],
        ], 201);
    }

    public function update(Request $request, int $webhook): JsonResponse
    {
        $tenantId = (int) auth()->user()->tenant_id;
        $model = $this->adminService->findWebhookForTenant($tenantId, $webhook);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'product_id' => ['required', 'exists:products,id'],
            'platform' => ['nullable', 'string', 'max:64'],
            'external_product_id' => ['nullable', 'string', 'max:191'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['product_id'] = (string) $validated['product_id'];

        return response()->json([
            'message' => 'Webhook atualizado.',
            'webhook' => $this->adminService->updateWebhook($tenantId, $model, $validated),
        ]);
    }

    public function regenerateUrl(int $webhook): JsonResponse
    {
        $tenantId = (int) auth()->user()->tenant_id;
        $model = $this->adminService->findWebhookForTenant($tenantId, $webhook);

        $result = $this->adminService->regenerateUrl($tenantId, $model);

        return response()->json([
            'message' => 'Nova URL gerada. A URL anterior deixará de funcionar.',
            'webhook' => $result['webhook'],
        ]);
    }

    public function showLog(int $log): JsonResponse
    {
        $tenantId = (int) auth()->user()->tenant_id;
        $model = \App\Models\EnrollmentWebhookLog::query()->findOrFail($log);

        return response()->json([
            'log' => $this->adminService->logDetail($tenantId, $model),
        ]);
    }
}
