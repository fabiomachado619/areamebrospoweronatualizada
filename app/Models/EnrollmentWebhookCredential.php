<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class EnrollmentWebhookCredential extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'webhook_key',
        'product_id',
        'platform',
        'external_product_id',
        'token_prefix',
        'token_hash',
        'signing_secret',
        'is_active',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function logs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EnrollmentWebhookLog::class, 'enrollment_webhook_id');
    }

    public function setSigningSecretAttribute(?string $value): void
    {
        $this->attributes['signing_secret'] = $value !== null && $value !== ''
            ? Crypt::encryptString($value)
            : null;
    }

    public function getSigningSecretPlain(): ?string
    {
        $encrypted = $this->attributes['signing_secret'] ?? null;
        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    public function verifyToken(string $plainToken): bool
    {
        return password_verify($plainToken, $this->token_hash);
    }

    public static function hashToken(string $plainToken): string
    {
        return password_hash($plainToken, PASSWORD_DEFAULT);
    }

    public static function tokenPrefixFromPlain(string $plainToken): string
    {
        return substr($plainToken, 0, 12);
    }

    /**
     * @return array{model: self, plain_token: string}
     */
    public static function issueForTenant(int $tenantId, ?string $name = null, ?string $signingSecret = null): array
    {
        return static::createWebhook(
            tenantId: $tenantId,
            name: $name ?: 'n8n',
            productId: null,
            platform: null,
            externalProductId: null,
            isActive: true,
            signingSecret: $signingSecret,
            deactivateOthers: true,
        );
    }

    /**
     * @return array{model: self, plain_token: string}
     */
    public static function createWebhook(
        int $tenantId,
        string $name,
        ?string $productId = null,
        ?string $platform = null,
        ?string $externalProductId = null,
        bool $isActive = true,
        ?string $signingSecret = null,
        bool $deactivateOthers = false,
    ): array {
        if ($deactivateOthers) {
            static::query()
                ->where('tenant_id', $tenantId)
                ->update(['is_active' => false]);
        }

        $plain = Str::random(64);
        $webhookKey = static::generateUniqueWebhookKey();

        $model = static::create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'webhook_key' => $webhookKey,
            'product_id' => $productId,
            'platform' => $platform,
            'external_product_id' => $externalProductId,
            'token_prefix' => static::tokenPrefixFromPlain($plain),
            'token_hash' => static::hashToken($plain),
            'signing_secret' => $signingSecret,
            'is_active' => $isActive,
        ]);

        return ['model' => $model, 'plain_token' => $plain];
    }

    /**
     * @return array{model: self, plain_token: string}
     */
    public function regenerateToken(): array
    {
        $plain = Str::random(64);

        $this->fill([
            'token_prefix' => static::tokenPrefixFromPlain($plain),
            'token_hash' => static::hashToken($plain),
        ]);
        $this->save();

        return ['model' => $this, 'plain_token' => $plain];
    }

    /**
     * @return array{model: self, webhook_key: string}
     */
    public function regenerateWebhookKey(): array
    {
        $key = static::generateUniqueWebhookKey();

        $this->fill(['webhook_key' => $key]);
        $this->save();

        return ['model' => $this, 'webhook_key' => $key];
    }

    public static function generateUniqueWebhookKey(): string
    {
        do {
            $key = Str::lower(Str::random(24));
        } while (static::query()->where('webhook_key', $key)->exists());

        return $key;
    }

    public static function findByWebhookKey(string $webhookKey): ?self
    {
        $webhookKey = trim($webhookKey);
        if ($webhookKey === '') {
            return null;
        }

        return static::query()->where('webhook_key', $webhookKey)->first();
    }

    public function webhookUrl(): string
    {
        return url('/api/webhooks/enrollment/'.$this->webhook_key);
    }

    public function touchLastUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->save();
    }

    public static function findByPlainToken(string $plainToken): ?self
    {
        if (strlen($plainToken) < 12) {
            return null;
        }

        $credential = static::query()
            ->where('token_prefix', static::tokenPrefixFromPlain($plainToken))
            ->where('is_active', true)
            ->first();

        if (! $credential || ! $credential->verifyToken($plainToken)) {
            return null;
        }

        return $credential;
    }
}
