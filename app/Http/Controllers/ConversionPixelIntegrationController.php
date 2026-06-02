<?php

namespace App\Http\Controllers;

use App\Models\ConversionPixelIntegration;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConversionPixelIntegrationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);
        $tenantId = auth()->user()->tenant_id;
        $this->ensureProductIdsBelongToTenant($tenantId, $validated['product_ids'] ?? []);

        $integration = ConversionPixelIntegration::create([
            'tenant_id' => $tenantId,
            'platform' => $validated['platform'],
            'name' => $validated['name'],
            'config' => $validated['config'],
            'access_token' => $validated['access_token'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        if (! empty($validated['product_ids'])) {
            $integration->products()->sync($validated['product_ids']);
        }

        $integration->load('products:id,name');

        return response()->json([
            'integration' => self::integrationToArray($integration),
        ], 201);
    }

    public function update(Request $request, ConversionPixelIntegration $conversionPixelIntegration): JsonResponse
    {
        $this->authorizeIntegration($conversionPixelIntegration);
        $validated = $this->validatePayload($request, $conversionPixelIntegration->platform);
        $this->ensureProductIdsBelongToTenant($conversionPixelIntegration->tenant_id, $validated['product_ids'] ?? []);

        $conversionPixelIntegration->update([
            'name' => $validated['name'],
            'config' => $validated['config'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        if (array_key_exists('access_token', $validated)) {
            $conversionPixelIntegration->access_token = $validated['access_token'] !== '' && $validated['access_token'] !== null
                ? $validated['access_token']
                : null;
            $conversionPixelIntegration->save();
        }

        if (array_key_exists('product_ids', $validated)) {
            $conversionPixelIntegration->products()->sync($validated['product_ids'] ?? []);
        }

        $conversionPixelIntegration->load('products:id,name');

        return response()->json([
            'integration' => self::integrationToArray($conversionPixelIntegration->fresh(['products:id,name'])),
        ]);
    }

    public function destroy(ConversionPixelIntegration $conversionPixelIntegration): JsonResponse
    {
        $this->authorizeIntegration($conversionPixelIntegration);
        $conversionPixelIntegration->products()->detach();
        $conversionPixelIntegration->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?string $fixedPlatform = null): array
    {
        $platform = $fixedPlatform ?? $request->input('platform');

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'config' => ['required', 'array'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['string', 'exists:products,id'],
        ];

        if ($fixedPlatform === null) {
            $rules['platform'] = ['required', 'string', Rule::in(ConversionPixelIntegration::PLATFORMS)];
        }

        if (in_array($platform, [ConversionPixelIntegration::PLATFORM_META, ConversionPixelIntegration::PLATFORM_TIKTOK], true)) {
            $rules['config.pixel_id'] = ['required', 'string', 'max:64'];
            $rules['access_token'] = [$request->isMethod('post') ? 'required' : 'nullable', 'string', 'max:500'];
            $rules['config.fire_purchase_on_pix'] = ['nullable', 'boolean'];
            $rules['config.fire_purchase_on_boleto'] = ['nullable', 'boolean'];
            $rules['config.disable_order_bump_events'] = ['nullable', 'boolean'];
        } elseif ($platform === ConversionPixelIntegration::PLATFORM_GOOGLE_ADS) {
            $rules['config.conversion_id'] = ['required', 'string', 'max:64'];
            $rules['config.conversion_label'] = ['nullable', 'string', 'max:64'];
            $rules['config.fire_purchase_on_pix'] = ['nullable', 'boolean'];
            $rules['config.fire_purchase_on_boleto'] = ['nullable', 'boolean'];
            $rules['config.disable_order_bump_events'] = ['nullable', 'boolean'];
        } elseif ($platform === ConversionPixelIntegration::PLATFORM_GOOGLE_ANALYTICS) {
            $rules['config.measurement_id'] = ['required', 'string', 'max:64'];
            $rules['config.fire_purchase_on_pix'] = ['nullable', 'boolean'];
            $rules['config.fire_purchase_on_boleto'] = ['nullable', 'boolean'];
            $rules['config.disable_order_bump_events'] = ['nullable', 'boolean'];
        } elseif ($platform === ConversionPixelIntegration::PLATFORM_CUSTOM_SCRIPT) {
            $rules['config.script'] = ['required', 'string', 'max:65535'];
        }

        $validated = $request->validate($rules);
        if ($fixedPlatform !== null) {
            $validated['platform'] = $fixedPlatform;
        }

        $validated['config'] = $this->sanitizeConfig($validated['platform'], $validated['config']);

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function sanitizeConfig(string $platform, array $config): array
    {
        $flags = [];
        foreach (['fire_purchase_on_pix', 'fire_purchase_on_boleto', 'disable_order_bump_events'] as $key) {
            if (array_key_exists($key, $config)) {
                $flags[$key] = filter_var($config[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        $base = match ($platform) {
            ConversionPixelIntegration::PLATFORM_META, ConversionPixelIntegration::PLATFORM_TIKTOK => [
                'pixel_id' => trim((string) ($config['pixel_id'] ?? '')),
            ],
            ConversionPixelIntegration::PLATFORM_GOOGLE_ADS => [
                'conversion_id' => trim((string) ($config['conversion_id'] ?? '')),
                'conversion_label' => trim((string) ($config['conversion_label'] ?? '')),
            ],
            ConversionPixelIntegration::PLATFORM_GOOGLE_ANALYTICS => [
                'measurement_id' => trim((string) ($config['measurement_id'] ?? '')),
            ],
            ConversionPixelIntegration::PLATFORM_CUSTOM_SCRIPT => [
                'script' => (string) ($config['script'] ?? ''),
            ],
            default => $config,
        };

        if (in_array($platform, [
            ConversionPixelIntegration::PLATFORM_META,
            ConversionPixelIntegration::PLATFORM_TIKTOK,
            ConversionPixelIntegration::PLATFORM_GOOGLE_ADS,
            ConversionPixelIntegration::PLATFORM_GOOGLE_ANALYTICS,
        ], true)) {
            $defaults = \App\Models\Product::defaultConversionPixelEntryFlags();
            $base = array_merge($base, array_merge($defaults, $flags));
        }

        return $base;
    }

    /**
     * @param  list<string>  $productIds
     */
    private function ensureProductIdsBelongToTenant(?int $tenantId, array $productIds): void
    {
        if ($productIds === []) {
            return;
        }

        $valid = Product::forTenant($tenantId)->whereIn('id', $productIds)->pluck('id')->all();
        $invalid = array_diff($productIds, $valid);
        if ($invalid !== []) {
            abort(422, 'Produto inválido para este tenant.');
        }
    }

    private function authorizeIntegration(ConversionPixelIntegration $integration): void
    {
        if ($integration->tenant_id !== auth()->user()->tenant_id) {
            abort(404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function integrationToArray(ConversionPixelIntegration $integration, bool $maskToken = true): array
    {
        $hasToken = $integration->access_token !== null && $integration->access_token !== '';
        $config = $integration->config ?? [];
        $flags = $integration->entryBehaviorFlags();

        return [
            'id' => $integration->id,
            'platform' => $integration->platform,
            'name' => $integration->name,
            'config' => $config,
            'summary' => $integration->summaryLabel(),
            'has_access_token' => $hasToken,
            'access_token_masked' => $hasToken ? '••••••••' : '',
            'access_token' => $maskToken ? '' : ($integration->access_token ?? ''),
            'is_active' => (bool) $integration->is_active,
            'configured' => $integration->hasConfiguredCredentials(),
            'product_ids' => $integration->relationLoaded('products')
                ? $integration->products->pluck('id')->values()->all()
                : $integration->products()->pluck('products.id')->all(),
            'products' => $integration->relationLoaded('products')
                ? $integration->products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values()->all()
                : [],
            'fire_purchase_on_pix' => $flags['fire_purchase_on_pix'],
            'fire_purchase_on_boleto' => $flags['fire_purchase_on_boleto'],
            'disable_order_bump_events' => $flags['disable_order_bump_events'],
        ];
    }
}
