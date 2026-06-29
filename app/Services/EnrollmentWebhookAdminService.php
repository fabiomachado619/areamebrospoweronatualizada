<?php

namespace App\Services;

use App\Models\EnrollmentExternalProductMapping;
use App\Models\EnrollmentWebhookCredential;
use App\Models\EnrollmentWebhookLog;
use App\Models\Product;

class EnrollmentWebhookAdminService
{
    /**
     * @return array<string, mixed>
     */
    public function buildTabPayload(int $tenantId): array
    {
        $webhooks = EnrollmentWebhookCredential::query()
            ->where('tenant_id', $tenantId)
            ->with('product:id,name,checkout_slug')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (EnrollmentWebhookCredential $w) => $this->mapWebhookRow($w))
            ->values()
            ->all();

        $logs = EnrollmentWebhookLog::query()
            ->where('tenant_id', $tenantId)
            ->with(['credential:id,name', 'courseProduct:id,name'])
            ->orderByDesc('processed_at')
            ->limit(50)
            ->get()
            ->map(fn (EnrollmentWebhookLog $log) => $this->mapLogRow($log))
            ->values()
            ->all();

        $courses = Product::query()
            ->where('tenant_id', $tenantId)
            ->where('type', Product::TYPE_AREA_MEMBROS)
            ->where('is_member_hub', false)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name])
            ->values()
            ->all();

        return [
            'webhooks' => $webhooks,
            'webhook_logs' => $logs,
            'webhook_url_pattern' => url('/api/webhooks/enrollment/{webhook_key}'),
            'webhook_course_options' => $courses,
            'webhook_platform_options' => ['kiwify', 'hotmart', 'gg_checkout', 'cartpanda', 'manual', 'outro'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{webhook: array<string, mixed>}
     */
    public function createWebhook(int $tenantId, array $data): array
    {
        $product = $this->resolveTenantCourse($tenantId, (string) $data['product_id']);

        $issued = EnrollmentWebhookCredential::createWebhook(
            tenantId: $tenantId,
            name: (string) $data['name'],
            productId: $product->id,
            platform: $this->nullableString($data['platform'] ?? null),
            externalProductId: $this->nullableString($data['external_product_id'] ?? null),
            isActive: (bool) ($data['is_active'] ?? true),
        );

        $this->syncExternalMapping(
            $tenantId,
            $issued['model']->platform,
            $issued['model']->external_product_id,
            $product->id
        );

        return [
            'webhook' => $this->mapWebhookRow($issued['model']->fresh()->load('product:id,name,checkout_slug')),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateWebhook(int $tenantId, EnrollmentWebhookCredential $webhook, array $data): array
    {
        $this->assertTenantOwns($tenantId, $webhook);

        $product = $this->resolveTenantCourse($tenantId, (string) $data['product_id']);

        $webhook->fill([
            'name' => (string) $data['name'],
            'product_id' => $product->id,
            'platform' => $this->nullableString($data['platform'] ?? null),
            'external_product_id' => $this->nullableString($data['external_product_id'] ?? null),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
        $webhook->save();

        $this->syncExternalMapping(
            $tenantId,
            $webhook->platform,
            $webhook->external_product_id,
            $product->id
        );

        return $this->mapWebhookRow($webhook->fresh()->load('product:id,name,checkout_slug'));
    }

    /**
     * @return array{webhook: array<string, mixed>}
     */
    public function regenerateUrl(int $tenantId, EnrollmentWebhookCredential $webhook): array
    {
        $this->assertTenantOwns($tenantId, $webhook);

        $issued = $webhook->regenerateWebhookKey();

        return [
            'webhook' => $this->mapWebhookRow($issued['model']->fresh()->load('product:id,name,checkout_slug')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function logDetail(int $tenantId, EnrollmentWebhookLog $log): array
    {
        if ((int) $log->tenant_id !== $tenantId) {
            abort(404);
        }

        return $this->mapLogRow($log->load(['credential:id,name', 'courseProduct:id,name']), true);
    }

    public function findWebhookForTenant(int $tenantId, int $webhookId): EnrollmentWebhookCredential
    {
        $webhook = EnrollmentWebhookCredential::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($webhookId)
            ->first();

        if (! $webhook) {
            abort(404);
        }

        return $webhook;
    }

    private function assertTenantOwns(int $tenantId, EnrollmentWebhookCredential $webhook): void
    {
        if ((int) $webhook->tenant_id !== $tenantId) {
            abort(404);
        }
    }

    private function resolveTenantCourse(int $tenantId, string $productId): Product
    {
        $product = Product::query()->find($productId);
        if (! $product
            || (int) $product->tenant_id !== $tenantId
            || $product->type !== Product::TYPE_AREA_MEMBROS
            || $product->isMemberHub()
        ) {
            abort(422, 'Curso inválido para este tenant.');
        }

        return $product;
    }

    private function syncExternalMapping(int $tenantId, ?string $platform, ?string $externalProductId, string $productId): void
    {
        if ($platform === null || $platform === '' || $externalProductId === null || $externalProductId === '') {
            return;
        }

        EnrollmentExternalProductMapping::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'platform' => $platform,
                'external_product_id' => $externalProductId,
            ],
            ['product_id' => $productId]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function mapWebhookRow(EnrollmentWebhookCredential $webhook): array
    {
        $stats = EnrollmentWebhookLog::query()
            ->where('enrollment_webhook_id', $webhook->id)
            ->selectRaw('
                SUM(CASE WHEN action = ? THEN 1 ELSE 0 END) as total_errors,
                SUM(CASE WHEN action != ? THEN 1 ELSE 0 END) as total_processed
            ', [EnrollmentWebhookLog::ACTION_ERROR, EnrollmentWebhookLog::ACTION_ERROR])
            ->first();

        return [
            'id' => $webhook->id,
            'name' => $webhook->name,
            'platform' => $webhook->platform,
            'external_product_id' => $webhook->external_product_id,
            'product_id' => $webhook->product_id,
            'product_name' => $webhook->product?->name,
            'is_active' => (bool) $webhook->is_active,
            'webhook_key' => $webhook->webhook_key,
            'webhook_url' => $webhook->webhookUrl(),
            'endpoint_url' => $webhook->webhookUrl(),
            'last_used_at' => $webhook->last_used_at?->toIso8601String(),
            'last_used_label' => $webhook->last_used_at?->format('d/m/Y H:i') ?? '—',
            'total_processed' => (int) ($stats->total_processed ?? 0),
            'total_errors' => (int) ($stats->total_errors ?? 0),
            'created_at' => $webhook->created_at?->format('d/m/Y H:i'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapLogRow(EnrollmentWebhookLog $log, bool $includePayload = false): array
    {
        return [
            'id' => $log->id,
            'processed_at' => $log->processed_at?->format('d/m/Y H:i:s') ?? '—',
            'webhook_name' => $log->credential?->name ?? '—',
            'platform' => $log->platform ?? '—',
            'event' => $log->event ?? '—',
            'status' => $log->status ?? '—',
            'email' => $log->email ?? '—',
            'course_name' => $log->courseProduct?->name ?? '—',
            'action' => $log->action,
            'email_sent' => (bool) $log->email_sent,
            'error_message' => $log->error_message,
            'payload' => $includePayload ? $log->payload : null,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
