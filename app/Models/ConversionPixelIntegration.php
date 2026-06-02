<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class ConversionPixelIntegration extends Model
{
    public const PLATFORM_META = 'meta';

    public const PLATFORM_TIKTOK = 'tiktok';

    public const PLATFORM_GOOGLE_ADS = 'google_ads';

    public const PLATFORM_GOOGLE_ANALYTICS = 'google_analytics';

    public const PLATFORM_CUSTOM_SCRIPT = 'custom_script';

    /** @var list<string> */
    public const PLATFORMS = [
        self::PLATFORM_META,
        self::PLATFORM_TIKTOK,
        self::PLATFORM_GOOGLE_ADS,
        self::PLATFORM_GOOGLE_ANALYTICS,
        self::PLATFORM_CUSTOM_SCRIPT,
    ];

    protected $fillable = [
        'tenant_id',
        'platform',
        'name',
        'config',
        'access_token',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'access_token' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    public function scopeForTenant($query, ?int $tenantId)
    {
        if ($tenantId === null) {
            return $query->whereNull('tenant_id');
        }

        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'conversion_pixel_integration_product')
            ->withTimestamps();
    }

    /**
     * Só aplica quando o produto está marcado explicitamente na integração.
     */
    public function appliesToProduct(?string $productId): bool
    {
        if ($productId === null || $productId === '') {
            return false;
        }

        if ($this->relationLoaded('products')) {
            $linked = $this->products->pluck('id')->map(fn ($id) => (string) $id)->all();
        } else {
            $linked = $this->products()->pluck('products.id')->map(fn ($id) => (string) $id)->all();
        }

        if ($linked === []) {
            return false;
        }

        return in_array((string) $productId, $linked, true);
    }

    /**
     * @return array{fire_purchase_on_pix: bool, fire_purchase_on_boleto: bool, disable_order_bump_events: bool}
     */
    public function entryBehaviorFlags(): array
    {
        $config = is_array($this->config) ? $this->config : [];
        $flags = Product::defaultConversionPixelEntryFlags();
        foreach (['fire_purchase_on_pix', 'fire_purchase_on_boleto', 'disable_order_bump_events'] as $key) {
            if (array_key_exists($key, $config)) {
                $flags[$key] = filter_var($config[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $flags;
    }

    public function supportsBehaviorFlags(): bool
    {
        return in_array($this->platform, [
            self::PLATFORM_META,
            self::PLATFORM_TIKTOK,
            self::PLATFORM_GOOGLE_ADS,
            self::PLATFORM_GOOGLE_ANALYTICS,
        ], true);
    }

    public static function platformLabels(): array
    {
        return [
            self::PLATFORM_META => 'Meta Ads',
            self::PLATFORM_TIKTOK => 'TikTok Ads',
            self::PLATFORM_GOOGLE_ADS => 'Google Ads',
            self::PLATFORM_GOOGLE_ANALYTICS => 'Google Analytics',
            self::PLATFORM_CUSTOM_SCRIPT => 'Script personalizado',
        ];
    }

    /**
     * Uma entrada no formato usado por checkout / CAPI (sem flags de comportamento).
     *
     * @return array<string, mixed>|null
     */
    public function toPixelEntry(): ?array
    {
        $config = is_array($this->config) ? $this->config : [];
        $id = (string) $this->id;

        $entry = match ($this->platform) {
            self::PLATFORM_META, self::PLATFORM_TIKTOK => $this->entryForPixelPlatform($id),
            self::PLATFORM_GOOGLE_ADS => $this->entryForGoogleAds($id, $config),
            self::PLATFORM_GOOGLE_ANALYTICS => $this->entryForGoogleAnalytics($id, $config),
            self::PLATFORM_CUSTOM_SCRIPT => $this->entryForCustomScript($config),
            default => null,
        };

        if ($entry === null) {
            return null;
        }

        if ($this->supportsBehaviorFlags()) {
            return array_merge($entry, $this->entryBehaviorFlags());
        }

        return $entry;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function entryForPixelPlatform(string $entryId): ?array
    {
        $config = is_array($this->config) ? $this->config : [];
        $pixelId = trim((string) ($config['pixel_id'] ?? ''));
        if ($pixelId === '') {
            return null;
        }

        return [
            'id' => 'integration-'.$entryId,
            'pixel_id' => $pixelId,
            'access_token' => trim((string) ($this->access_token ?? '')),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    private function entryForGoogleAds(string $entryId, array $config): ?array
    {
        $conversionId = trim((string) ($config['conversion_id'] ?? ''));
        if ($conversionId === '') {
            return null;
        }

        return [
            'id' => 'integration-'.$entryId,
            'conversion_id' => $conversionId,
            'conversion_label' => trim((string) ($config['conversion_label'] ?? '')),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    private function entryForGoogleAnalytics(string $entryId, array $config): ?array
    {
        $measurementId = trim((string) ($config['measurement_id'] ?? ''));
        if ($measurementId === '') {
            return null;
        }

        return [
            'id' => 'integration-'.$entryId,
            'measurement_id' => $measurementId,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    private function entryForCustomScript(array $config): ?array
    {
        $script = trim((string) ($config['script'] ?? ''));
        if ($script === '') {
            return null;
        }

        return [
            'id' => 'integration-'.$this->id,
            'name' => $this->name,
            'script' => $script,
        ];
    }

    public function summaryLabel(): string
    {
        $config = is_array($this->config) ? $this->config : [];

        return match ($this->platform) {
            self::PLATFORM_META, self::PLATFORM_TIKTOK => trim((string) ($config['pixel_id'] ?? '')) ?: '—',
            self::PLATFORM_GOOGLE_ADS => trim((string) ($config['conversion_id'] ?? '')) ?: '—',
            self::PLATFORM_GOOGLE_ANALYTICS => trim((string) ($config['measurement_id'] ?? '')) ?: '—',
            self::PLATFORM_CUSTOM_SCRIPT => Str::limit(trim((string) ($config['script'] ?? '')), 40) ?: '—',
            default => '—',
        };
    }

    public function hasConfiguredCredentials(): bool
    {
        return $this->toPixelEntry() !== null;
    }
}
