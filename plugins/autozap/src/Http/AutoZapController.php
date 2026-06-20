<?php

namespace Plugins\AutoZap\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Plugins\AutoZap\Models\AutoZapConnection;
use Plugins\AutoZap\Providers\EvolutionApiProvider;
use Plugins\AutoZap\Providers\MenuiaProvider;
use Plugins\AutoZap\Providers\ZApiProvider;

require_once __DIR__ . '/../Models/AutoZapConnection.php';
require_once __DIR__ . '/../Providers/ZApiProvider.php';
require_once __DIR__ . '/../Providers/EvolutionApiProvider.php';
require_once __DIR__ . '/../Providers/MenuiaProvider.php';

class AutoZapController
{
    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    private function safeForProvider(string $provider, array $credentials): array
    {
        if ($provider === 'zapi') {
            $token = (string) ($credentials['token'] ?? '');
            $clientToken = (string) ($credentials['client_token'] ?? '');
            return [
                'base_url' => $credentials['base_url'] ?? 'https://api.z-api.io',
                'instance_id' => $credentials['instance_id'] ?? '',
                'has_token' => $token !== '',
                'has_client_token' => $clientToken !== '',
                'token_masked' => $token !== '' ? str_repeat('•', max(6, strlen($token) - 4)).substr($token, -4) : null,
                'client_token_masked' => $clientToken !== '' ? str_repeat('•', max(6, strlen($clientToken) - 4)).substr($clientToken, -4) : null,
            ];
        }
        if ($provider === 'evolution') {
            $key = (string) ($credentials['apikey'] ?? $credentials['api_key'] ?? '');
            return [
                'base_url' => $credentials['base_url'] ?? '',
                'instance' => $credentials['instance'] ?? '',
                'has_apikey' => $key !== '',
                'apikey_masked' => $key !== '' ? str_repeat('•', max(6, strlen($key) - 4)).substr($key, -4) : null,
            ];
        }
        if ($provider === 'menuia') {
            $appKey = (string) ($credentials['appkey'] ?? '');
            $authKey = (string) ($credentials['authkey'] ?? '');
            return [
                'device' => $credentials['device'] ?? '',
                'has_appkey' => $appKey !== '',
                'has_authkey' => $authKey !== '',
                'appkey_masked' => $appKey !== '' ? str_repeat('•', max(6, strlen($appKey) - 4)).substr($appKey, -4) : null,
                'authkey_masked' => $authKey !== '' ? str_repeat('•', max(6, strlen($authKey) - 4)).substr($authKey, -4) : null,
            ];
        }
        return [];
    }

    public function index(): Response
    {
        return Inertia::render('Plugin/AutoZap/Index');
    }

    public function getConnection(Request $request): JsonResponse
    {
        $tenantId = $request->user()?->tenant_id;
        $conn = AutoZapConnection::forTenant($tenantId)->first();

        $safeByProvider = null;
        $safe = null;
        if ($conn) {
            $safeByProvider = [];
            foreach (['zapi', 'evolution', 'menuia'] as $p) {
                $cred = $conn->credentialsForProvider($p);
                if ($cred !== []) {
                    $safeByProvider[$p] = $this->safeForProvider($p, $cred);
                } else {
                    $safeByProvider[$p] = null;
                }
            }
            if (is_string($conn->provider) && $conn->provider !== '') {
                $safe = $safeByProvider[$conn->provider] ?? null;
            }
        }

        return response()->json([
            'connected' => ($conn?->is_active ?? false) && ($conn?->hasCredentials($conn?->provider) ?? false),
            'provider' => $conn?->provider,
            'has_credentials' => $conn ? $conn->hasCredentials($conn->provider) : false,
            'safe_credentials' => $safe,
            'safe_credentials_by_provider' => $safeByProvider,
        ]);
    }

    public function saveConnection(Request $request): JsonResponse
    {
        $tenantId = $request->user()?->tenant_id;
        $existing = AutoZapConnection::forTenant($tenantId)->first();
        $validated = $request->validate([
            'provider' => ['required', 'string', 'in:zapi,evolution,menuia'],
            'is_active' => ['nullable', 'boolean'],
            'credentials' => ['required', 'array'],
        ]);

        $provider = (string) $validated['provider'];
        $cred = is_array($validated['credentials']) ? $validated['credentials'] : [];
        $existingProviderCred = $existing ? $existing->credentialsForProvider($provider) : [];

        if ($provider === 'zapi') {
            $hasExistingToken = $existing && ! empty($existingProviderCred['token'] ?? '');
            $request->validate([
                'credentials.base_url' => ['nullable', 'string', 'max:255'],
                'credentials.instance_id' => ['required', 'string', 'max:128'],
                'credentials.token' => [$hasExistingToken ? 'nullable' : 'required', 'string', 'max:255'],
                'credentials.client_token' => ['nullable', 'string', 'max:255'],
            ]);

            // Merge: do not overwrite secrets with empty values.
            if ($existing && is_array($existingProviderCred)) {
                if (empty($cred['token'] ?? '')) {
                    $cred['token'] = $existingProviderCred['token'] ?? '';
                }
                if (array_key_exists('client_token', $cred) && empty($cred['client_token'] ?? '') && ! empty($existingProviderCred['client_token'] ?? '')) {
                    $cred['client_token'] = $existingProviderCred['client_token'];
                }
            }
        } elseif ($provider === 'evolution') {
            $hasExistingKey = $existing && ! empty($existingProviderCred['apikey'] ?? $existingProviderCred['api_key'] ?? '');
            $request->validate([
                'credentials.base_url' => ['required', 'string', 'max:255'],
                'credentials.apikey' => [$hasExistingKey ? 'nullable' : 'required', 'string', 'max:255'],
                'credentials.instance' => ['required', 'string', 'max:128'],
            ]);

            if ($existing && is_array($existingProviderCred)) {
                if (empty($cred['apikey'] ?? '')) {
                    $cred['apikey'] = $existingProviderCred['apikey'] ?? ($existingProviderCred['api_key'] ?? '');
                }
            }
        } else {
            $hasExistingAppKey = $existing && ! empty($existingProviderCred['appkey'] ?? '');
            $hasExistingAuthKey = $existing && ! empty($existingProviderCred['authkey'] ?? '');
            $request->validate([
                'credentials.appkey' => [$hasExistingAppKey ? 'nullable' : 'required', 'string', 'max:255'],
                'credentials.authkey' => [$hasExistingAuthKey ? 'nullable' : 'required', 'string', 'max:255'],
                'credentials.device' => ['required', 'string', 'max:255'],
            ]);

            // Merge: do not overwrite secrets with empty values.
            if ($existing && is_array($existingProviderCred)) {
                if (empty($cred['appkey'] ?? '')) {
                    $cred['appkey'] = $existingProviderCred['appkey'] ?? '';
                }
                if (empty($cred['authkey'] ?? '')) {
                    $cred['authkey'] = $existingProviderCred['authkey'] ?? '';
                }
            }
        }

        // Persist credentials per provider, so switching providers doesn't wipe previous configs.
        $providers = [];
        if ($existing) {
            $existingCred = $existing->credentials;
            if (is_array($existingCred) && isset($existingCred['providers']) && is_array($existingCred['providers'])) {
                $providers = $existingCred['providers'];
            } elseif (is_array($existingCred) && $existing->provider) {
                // Migrate legacy flat credentials to providers map.
                $providers[$existing->provider] = $existingCred;
            }
        }
        $providers[$provider] = $cred;

        $conn = AutoZapConnection::updateOrCreate(
            ['tenant_id' => $tenantId],
            [
                'provider' => $provider,
                'is_active' => (bool) ($validated['is_active'] ?? true),
                'credentials' => ['providers' => $providers],
            ]
        );

        return response()->json(['ok' => true, 'id' => $conn->id]);
    }

    public function testConnection(Request $request): JsonResponse
    {
        $tenantId = $request->user()?->tenant_id;
        $conn = AutoZapConnection::forTenant($tenantId)->first();
        if (! $conn || ! $conn->hasCredentials($conn->provider)) {
            return response()->json(['ok' => false, 'message' => 'Configure a conexão primeiro.'], 422);
        }

        $cred = $conn->credentialsForProvider($conn->provider);
        $provider = match ($conn->provider) {
            'zapi' => new ZApiProvider($cred),
            'evolution' => new EvolutionApiProvider($cred),
            'menuia' => new MenuiaProvider($cred),
            default => throw new \RuntimeException('Provedor AutoZap inválido.'),
        };

        $provider->testConnection();

        return response()->json(['ok' => true]);
    }
}

