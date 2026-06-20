<?php

namespace Plugins\AutoZap\Models;

use Illuminate\Database\Eloquent\Model;

class AutoZapConnection extends Model
{
    protected $table = 'autozap_connections';

    protected $fillable = [
        'tenant_id',
        'provider',
        'is_active',
        'credentials',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'credentials' => 'encrypted:array',
    ];

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $tenantId === null
            ? $query->whereNull('tenant_id')
            : $query->where('tenant_id', $tenantId);
    }

    /**
     * Return credentials for a given provider.
     *
     * Supports legacy format (flat array) and new format:
     * - ['providers' => ['zapi' => [...], 'evolution' => [...], 'menuia' => [...]]]
     *
     * @return array<string, mixed>
     */
    public function credentialsForProvider(?string $provider = null): array
    {
        $provider = $provider ?: ($this->provider ?? null);
        if (! is_string($provider) || $provider === '') {
            return [];
        }

        $cred = $this->credentials;
        if (! is_array($cred) || $cred === []) {
            return [];
        }

        if (isset($cred['providers']) && is_array($cred['providers'])) {
            $by = $cred['providers'];
            $p = $by[$provider] ?? [];
            return is_array($p) ? $p : [];
        }

        // Legacy: credentials are stored flat for the currently active provider.
        return $cred;
    }

    public function hasCredentials(?string $provider = null): bool
    {
        $pcred = $this->credentialsForProvider($provider);
        foreach ($pcred as $v) {
            if (is_string($v) && trim($v) !== '') return true;
            if (is_numeric($v)) return true;
            if (is_bool($v) && $v === true) return true;
        }
        return $pcred !== [];
    }
}

