<?php

namespace App\Gateways\Spacepag;

use App\Gateways\Contracts\GatewayDriver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SpacepagDriver implements GatewayDriver
{
    private const BASE_URL = 'https://api.spacepag.com/v1';

    private const SLOW_STEP_MS = 1000;

    /** @var list<string> */
    private const AUTH_MODE_ORDER = ['pk_sk', 'sk', 'pk', 'bearer_sk'];

    public function testConnection(array $credentials): bool
    {
        return $this->detectAuthMode($credentials) !== null;
    }

    /**
     * Descobre qual combinação de headers a Spacepag aceita e retorna o modo (para gravar em credentials).
     */
    public function detectAuthMode(array $credentials): ?string
    {
        [$public, $secret] = $this->resolveKeyPair($credentials);
        if ($public === '' && $secret === '') {
            return null;
        }

        $stored = trim((string) ($credentials['auth_mode'] ?? ''));
        if ($stored !== '' && in_array($stored, self::AUTH_MODE_ORDER, true)) {
            if ($this->probeAuthMode($credentials, $stored, $public, $secret)) {
                return $stored;
            }
        }

        foreach (self::AUTH_MODE_ORDER as $mode) {
            if ($this->probeAuthMode($credentials, $mode, $public, $secret)) {
                Log::info('Spacepag: modo de autenticação detectado', ['auth_mode' => $mode]);

                return $mode;
            }
        }

        Log::warning('Spacepag: nenhum modo de autenticação funcionou', [
            'has_public' => $public !== '',
            'has_secret' => $secret !== '',
            'public_prefix' => $public !== '' ? substr($public, 0, 7) : null,
            'secret_prefix' => $secret !== '' ? substr($secret, 0, 7) : null,
        ]);

        return null;
    }

    public function createPixPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $postbackUrl
    ): array {
        [$publicKey, $secretKey] = $this->resolveKeyPair($credentials);
        if ($publicKey === '' && $secretKey === '') {
            throw new \RuntimeException('Spacepag: configure a chave pública (pk_…) e/ou a chave privada (sk_…) em Integrações → Gateways.');
        }

        $document = $this->normalizeDocument((string) ($consumer['document'] ?? ''));
        $documentType = strlen($document) === 14 ? 'cnpj' : 'cpf';
        $name = $this->sanitizeName((string) ($consumer['name'] ?? ''));
        $email = $this->sanitizeEmail((string) ($consumer['email'] ?? ''));
        if ($email === '') {
            throw new \RuntimeException('Spacepag: e-mail do cliente é obrigatório.');
        }

        $phone = $this->normalizeCustomerPhone((string) ($consumer['phone'] ?? ''));
        if ($phone === '') {
            throw new \RuntimeException('Spacepag: telefone do cliente é obrigatório para PIX. Inclua telefone no checkout ou marque o campo como obrigatório.');
        }

        $body = [
            'amount' => round($amount, 2),
            'customerName' => $name,
            'customerEmail' => $email,
            'customerPhone' => $phone,
            'customerDocument' => $document,
            'customerDocumentType' => $documentType,
            'description' => 'Pagamento de serviço',
            'metadata' => [
                'order_id' => (string) $externalId,
            ],
        ];

        $idempotencyKey = 'getfy-order-'.preg_replace('/[^a-zA-Z0-9_-]/', '', $externalId);
        $idempotencyKey = Str::limit($idempotencyKey, 200, '');

        $credentials = $this->ensureAuthMode($credentials);

        $start = microtime(true);
        try {
            $response = $this->requestWithAuth($credentials, function (PendingRequest $client) use ($body, $idempotencyKey) {
                return $client
                    ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                    ->post('/payments/transactions', $body);
            }, retryOnAuthFailure: false);
        } catch (ConnectionException $e) {
            throw new \RuntimeException('Spacepag: falha de conexão com a API. '.$e->getMessage(), 0, $e);
        }
        $ms = (int) round((microtime(true) - $start) * 1000);
        if ($ms >= self::SLOW_STEP_MS) {
            Log::info('Spacepag: slow pix create', [
                'order_id' => $externalId,
                'duration_ms' => $ms,
                'http_status' => $response->status(),
            ]);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new \RuntimeException('Spacepag: '.$this->formatApiErrorMessage($response, 'autenticação recusada — verifique pk_ e sk_ no painel Spacepag.'));
        }

        if (! $response->successful()) {
            throw new \RuntimeException('Spacepag: '.$this->formatApiErrorMessage($response, 'Erro ao gerar transação PIX.'));
        }

        $rawBody = (string) $response->body();
        $json = $this->decodeResponseJson($response, $rawBody);
        if (is_array($json) && array_key_exists('success', $json) && $json['success'] === false) {
            throw new \RuntimeException('Spacepag: '.$this->formatApiErrorMessage($response, 'API recusou criar o PIX.'));
        }

        $parsed = $this->resolvePixCreateResult($credentials, $response, $json, $externalId, $rawBody);
        if ($parsed['transaction_id'] === '') {
            Log::warning('Spacepag: PIX HTTP OK mas resposta não interpretada', [
                'order_id' => $externalId,
                'http_status' => $response->status(),
                'content_type' => $this->responseHeaderValue($response, 'Content-Type'),
                'location' => $this->responseHeaderValue($response, 'Location'),
                'body_length' => strlen($rawBody),
                'body_preview' => Str::limit(trim($rawBody), 500),
                'json_keys' => is_array($json) ? array_keys($json) : null,
            ]);
            throw new \RuntimeException('Spacepag: não foi possível ler o QR Code da resposta. A cobrança pode ter sido criada na Spacepag — não clique de novo; verifique o painel ou tente em alguns minutos.');
        }

        if (($parsed['copy_paste'] ?? '') === '' && ($parsed['qrcode'] ?? '') === '') {
            Log::warning('Spacepag: transação sem QR/copia-e-cola na resposta', [
                'transaction_id' => $parsed['transaction_id'],
            ]);
        }

        return [
            'transaction_id' => $parsed['transaction_id'],
            'qrcode' => $parsed['qrcode'],
            'copy_paste' => $parsed['copy_paste'],
            'raw' => is_array($parsed['raw'] ?? null) ? $parsed['raw'] : (is_array($json) ? $json : []),
        ];
    }

    public function createCardPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        array $card
    ): array {
        throw new \RuntimeException('Spacepag não suporta pagamento com cartão neste checkout. Use outro gateway.');
    }

    public function createBoletoPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $notificationUrl
    ): array {
        throw new \RuntimeException('Spacepag não suporta boleto neste checkout. Use outro gateway.');
    }

    public function getTransactionStatus(string $transactionId, array $credentials): ?string
    {
        $transactionId = trim($transactionId);
        if ($transactionId === '') {
            return null;
        }

        try {
            $response = $this->requestWithAuth($credentials, function (PendingRequest $client) use ($transactionId) {
                return $client->get('/payments/transactions/'.rawurlencode($transactionId));
            });
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $json = $this->decodeResponseJson($response);
        if (! is_array($json)) {
            return null;
        }

        $data = is_array($json['data'] ?? null) ? $json['data'] : $json;
        $status = $data['status'] ?? $json['status'] ?? null;

        return $this->mapApiStatusToInternal(is_string($status) ? $status : null);
    }

    /**
     * @param  callable(PendingRequest): Response  $callback
     */
    private function requestWithAuth(array $credentials, callable $callback, bool $retryOnAuthFailure = true): Response
    {
        $mode = trim((string) ($credentials['auth_mode'] ?? ''));
        if ($mode === '' || ! in_array($mode, self::AUTH_MODE_ORDER, true)) {
            $mode = $this->detectAuthMode($credentials);
        }
        if ($mode === null) {
            throw new \RuntimeException('Spacepag: não foi possível autenticar na API. Salve novamente as chaves em Integrações → Gateways → Spacepag.');
        }

        [$public, $secret] = $this->resolveKeyPair($credentials);
        $client = $this->httpClient($credentials, $this->authHeaders($mode, $public, $secret));
        $response = $callback($client);

        if (! $retryOnAuthFailure || ($response->status() !== 401 && $response->status() !== 403)) {
            return $response;
        }

        $redetected = $this->detectAuthMode(array_merge($credentials, ['auth_mode' => '']));
        if ($redetected === null || $redetected === $mode) {
            return $response;
        }

        $client = $this->httpClient($credentials, $this->authHeaders($redetected, $public, $secret));

        return $callback($client);
    }

    /**
     * @return array<string, mixed>
     */
    private function ensureAuthMode(array $credentials): array
    {
        $mode = trim((string) ($credentials['auth_mode'] ?? ''));
        if ($mode !== '' && in_array($mode, self::AUTH_MODE_ORDER, true)) {
            return $credentials;
        }

        $detected = $this->detectAuthMode($credentials);
        if ($detected === null) {
            return $credentials;
        }

        $credentials['auth_mode'] = $detected;

        return $credentials;
    }

    private function probeAuthMode(array $credentials, string $mode, string $public, string $secret): bool
    {
        $headers = $this->authHeaders($mode, $public, $secret);
        if ($headers === []) {
            return false;
        }

        foreach (['/organizations/balance', '/webhooks'] as $path) {
            try {
                $response = $this->httpClient($credentials, $headers)->get($path);
                if ($response->successful()) {
                    return true;
                }
                if ($response->status() !== 401 && $response->status() !== 403) {
                    Log::debug('Spacepag probe: resposta não-auth', [
                        'auth_mode' => $mode,
                        'path' => $path,
                        'status' => $response->status(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::debug('Spacepag probe exception', [
                    'auth_mode' => $mode,
                    'path' => $path,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(string $mode, string $public, string $secret): array
    {
        return match ($mode) {
            'pk_sk' => $public !== '' && $secret !== ''
                ? ['X-API-Key' => $public, 'X-API-Secret' => $secret]
                : [],
            'sk' => $secret !== ''
                ? ['X-API-Key' => $secret]
                : [],
            'pk' => $public !== ''
                ? ['X-API-Key' => $public]
                : [],
            'bearer_sk' => $secret !== ''
                ? ['Authorization' => 'Bearer '.$secret]
                : [],
            default => [],
        };
    }

    /**
     * @param  array<string, string>  $authHeaders
     */
    private function httpClient(array $credentials, array $authHeaders): PendingRequest
    {
        $options = [
            'connect_timeout' => $this->connectTimeoutSeconds($credentials),
        ];

        if ($this->shouldDisableProxy($credentials)) {
            $options['proxy'] = '';
        }

        if ($this->shouldForceIpv4ByDefault($credentials) && defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
            $options['curl'][CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        }

        return Http::baseUrl($this->baseUrl($credentials))
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeoutSeconds($credentials))
            ->withOptions($options)
            ->withHeaders(array_merge($authHeaders, [
                'User-Agent' => config('app.name', 'Power On'),
            ]));
    }

    /**
     * @return array{0: string, 1: string} [public, secret]
     */
    private function resolveKeyPair(array $credentials): array
    {
        $public = $this->normalizeApiKey((string) ($credentials['public_key'] ?? ''));
        $secret = $this->normalizeApiKey((string) ($credentials['secret_key'] ?? ''));

        $legacy = $this->normalizeApiKey((string) ($credentials['api_key'] ?? ''));
        if ($legacy !== '') {
            if (str_starts_with($legacy, 'pk_') && $public === '') {
                $public = $legacy;
            } elseif (str_starts_with($legacy, 'sk_') && $secret === '') {
                $secret = $legacy;
            } elseif ($secret === '') {
                $secret = $legacy;
            } elseif ($public === '') {
                $public = $legacy;
            }
        }

        return [$public, $secret];
    }

    private function normalizeApiKey(string $raw): string
    {
        $key = trim($raw);
        $key = preg_replace('/\s+/', '', $key) ?? '';
        if (preg_match('/^x-api-key:\s*(.+)$/i', $key, $m)) {
            $key = trim($m[1]);
        }
        if (preg_match('/^x-api-secret:\s*(.+)$/i', $key, $m)) {
            $key = trim($m[1]);
        }
        if (preg_match('/^bearer\s+(.+)$/i', $key, $m)) {
            $key = trim($m[1]);
        }

        return trim($key, " \t\n\r\0\x0B\"'");
    }

    private function formatApiErrorMessage(Response $response, string $fallback): string
    {
        $json = $this->decodeResponseJson($response);
        if (is_array($json)) {
            $message = $json['message'] ?? null;
            if (is_string($message) && trim($message) !== '') {
                return trim($message);
            }
        }

        return $fallback.' (HTTP '.$response->status().')';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeResponseJson(Response $response, ?string $rawBody = null): ?array
    {
        $body = $rawBody ?? (string) $response->body();
        $body = trim($body);
        if ($body === '') {
            return null;
        }

        if (str_starts_with($body, "\xEF\xBB\xBF")) {
            $body = substr($body, 3);
        }

        $flags = JSON_BIGINT_AS_STRING;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }

        $fromBody = json_decode($body, true, 512, $flags);
        if (json_last_error() === JSON_ERROR_NONE && is_array($fromBody)) {
            return $fromBody;
        }

        $decoded = $response->json();
        if (is_array($decoded)) {
            return $decoded;
        }
        if (is_object($decoded)) {
            $asArray = json_decode(json_encode($decoded), true);

            return is_array($asArray) ? $asArray : null;
        }

        if (preg_match('/\{[\s\S]*\}/', $body, $matches) === 1) {
            $fromMatch = json_decode($matches[0], true, 512, $flags);
            if (json_last_error() === JSON_ERROR_NONE && is_array($fromMatch)) {
                return $fromMatch;
            }
        }

        return null;
    }

    private function responseHeaderValue(Response $response, string $name): ?string
    {
        $value = $response->header($name);
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function extractTransactionIdFromLocation(Response $response): string
    {
        $location = $this->responseHeaderValue($response, 'Location');
        if ($location === null) {
            return '';
        }

        if (preg_match('~/payments/transactions/([^/?]+)~i', $location, $matches) === 1) {
            return trim(rawurldecode($matches[1]));
        }

        return '';
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return array{transaction_id: string, qrcode: ?string, copy_paste: ?string, raw: array<string, mixed>}
     */
    private function resolvePixCreateResult(
        array $credentials,
        Response $response,
        ?array $json,
        string $externalId,
        string $rawBody = ''
    ): array {
        if (is_array($json)) {
            $parsed = $this->parsePixCreateResponse($json);
            if ($parsed['transaction_id'] !== '') {
                return [
                    'transaction_id' => $parsed['transaction_id'],
                    'qrcode' => $parsed['qrcode'],
                    'copy_paste' => $parsed['copy_paste'],
                    'raw' => $json,
                ];
            }
        }

        $fromBody = $this->parsePixFromRawBody($rawBody);
        if ($fromBody['transaction_id'] !== '') {
            Log::info('Spacepag: PIX interpretado via fallback do corpo bruto', [
                'order_id' => $externalId,
                'transaction_id' => $fromBody['transaction_id'],
            ]);

            return [
                'transaction_id' => $fromBody['transaction_id'],
                'qrcode' => $fromBody['qrcode'],
                'copy_paste' => $fromBody['copy_paste'],
                'raw' => is_array($json) ? $json : [],
            ];
        }

        $transactionId = $this->extractTransactionIdFromLocation($response);
        if ($transactionId === '' && $rawBody !== '') {
            $transactionId = $this->extractTransactionIdFromRawBody($rawBody);
        }
        if ($transactionId !== '') {
            Log::info('Spacepag: PIX criado com corpo vazio/não-JSON; recuperando via Location/GET', [
                'order_id' => $externalId,
                'transaction_id' => $transactionId,
            ]);

            return $this->fetchPixTransactionDetails($credentials, $transactionId, $json);
        }

        return [
            'transaction_id' => '',
            'qrcode' => null,
            'copy_paste' => null,
            'raw' => is_array($json) ? $json : [],
        ];
    }

    /**
     * @return array{transaction_id: string, qrcode: ?string, copy_paste: ?string}
     */
    private function parsePixFromRawBody(string $rawBody): array
    {
        $rawBody = trim($rawBody);
        if ($rawBody === '') {
            return [
                'transaction_id' => '',
                'qrcode' => null,
                'copy_paste' => null,
            ];
        }

        $transactionId = $this->extractTransactionIdFromRawBody($rawBody);
        $copyPaste = null;
        $qrcode = null;

        if (preg_match('/"emv"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $rawBody, $emvMatch) === 1) {
            $copyPaste = stripcslashes($emvMatch[1]);
        } elseif (preg_match('/"emv"\s*:\s*"(000201[\s\S]*?)"/', $rawBody, $emvMatch) === 1) {
            $copyPaste = $emvMatch[1];
        }

        if (preg_match('/"image"\s*:\s*"(https?:[^"]+)"/i', $rawBody, $imageMatch) === 1) {
            $qrcode = stripcslashes($imageMatch[1]);
        }

        if ($transactionId === '' && $qrcode !== null && preg_match('/transaction_id=(txn_[^&"\s]+)/i', $qrcode, $txFromQr) === 1) {
            $transactionId = $txFromQr[1];
        }

        return [
            'transaction_id' => $transactionId,
            'qrcode' => $qrcode,
            'copy_paste' => $copyPaste,
        ];
    }

    private function extractTransactionIdFromRawBody(string $rawBody): string
    {
        if (preg_match('/"id"\s*:\s*"(txn_[^"]+)"/i', $rawBody, $match) === 1) {
            return trim($match[1]);
        }
        if (preg_match('/transaction_id=(txn_[^&"\s]+)/i', $rawBody, $match) === 1) {
            return trim($match[1]);
        }
        if (preg_match('/"external_id"\s*:\s*"([^"]+)"/i', $rawBody, $match) === 1) {
            return trim($match[1]);
        }

        return '';
    }

    /**
     * @param  array<string, mixed>|null  $createJson
     * @return array{transaction_id: string, qrcode: ?string, copy_paste: ?string, raw: array<string, mixed>}
     */
    private function fetchPixTransactionDetails(array $credentials, string $transactionId, ?array $createJson = null): array
    {
        $fallback = [
            'transaction_id' => $transactionId,
            'qrcode' => null,
            'copy_paste' => null,
            'raw' => is_array($createJson) ? $createJson : [],
        ];

        try {
            $response = $this->requestWithAuth($credentials, function (PendingRequest $client) use ($transactionId) {
                return $client->get('/payments/transactions/'.rawurlencode($transactionId));
            });
        } catch (\Throwable $e) {
            Log::warning('Spacepag: falha ao buscar transação após criar PIX', [
                'transaction_id' => $transactionId,
                'message' => $e->getMessage(),
            ]);

            return $fallback;
        }

        if (! $response->successful()) {
            return $fallback;
        }

        $json = $this->decodeResponseJson($response);
        if (! is_array($json)) {
            return $fallback;
        }

        $parsed = $this->parsePixCreateResponse($json);
        $parsed['transaction_id'] = $parsed['transaction_id'] !== '' ? $parsed['transaction_id'] : $transactionId;
        $parsed['raw'] = $json;

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $json  corpo JSON completo da API
     * @return array{transaction_id: string, qrcode: ?string, copy_paste: ?string}
     */
    private function parsePixCreateResponse(array $json): array
    {
        $payloads = [];
        $dataNode = $json['data'] ?? null;
        if (is_string($dataNode) && $dataNode !== '') {
            $decoded = json_decode($dataNode, true);
            if (is_array($decoded)) {
                $payloads[] = $decoded;
            }
        } elseif (is_array($dataNode)) {
            $payloads[] = $dataNode;
            if (isset($dataNode['transaction']) && is_array($dataNode['transaction'])) {
                $payloads[] = $dataNode['transaction'];
            }
        }
        if (isset($json['result']) && is_array($json['result'])) {
            $payloads[] = $json['result'];
        }
        if (isset($json['payment']) && is_array($json['payment'])) {
            $payloads[] = $json['payment'];
        }
        if (isset($json['transaction']) && is_array($json['transaction'])) {
            $payloads[] = $json['transaction'];
        }
        $payloads[] = $json;

        foreach ($payloads as $payload) {
            $transactionId = $this->extractTransactionIdFromPayload($payload);
            if ($transactionId === '') {
                continue;
            }
            [$copyPaste, $qrcode] = $this->extractPixFieldsFromPayload($payload);

            return [
                'transaction_id' => $transactionId,
                'qrcode' => $qrcode,
                'copy_paste' => $copyPaste,
            ];
        }

        return [
            'transaction_id' => '',
            'qrcode' => null,
            'copy_paste' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractTransactionIdFromPayload(array $data): string
    {
        $candidates = [];
        foreach (['transactionId', 'transaction_id', 'id', 'externalId', 'external_id'] as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $candidates[] = trim($value);
            } elseif (is_numeric($value)) {
                $candidates[] = (string) $value;
            }
        }
        foreach ($candidates as $candidate) {
            if (str_starts_with($candidate, 'txn_')) {
                return $candidate;
            }
        }
        if ($candidates !== []) {
            return $candidates[0];
        }

        if (isset($data['transaction']) && is_array($data['transaction'])) {
            $nested = $this->extractTransactionIdFromPayload($data['transaction']);
            if ($nested !== '') {
                return $nested;
            }
        }

        $reference = $data['referenceCode'] ?? $data['reference_code'] ?? null;
        if (is_string($reference) && trim($reference) !== '') {
            return trim($reference);
        }

        if (isset($data['pix']) && is_array($data['pix'])) {
            $txid = $data['pix']['txid'] ?? $data['pix']['txId'] ?? null;
            if (is_string($txid) && trim($txid) !== '') {
                return trim($txid);
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ?string, 1: ?string} [copy_paste, qrcode_image]
     */
    private function extractPixFieldsFromPayload(array $data): array
    {
        $pix = $data['pix'] ?? null;
        if (! is_array($pix)) {
            return [null, null];
        }

        $copyPaste = null;
        $image = null;

        $qrNode = $pix['qrCode'] ?? $pix['qrcode'] ?? $pix['qr_code'] ?? $pix['QRCode'] ?? null;
        if (is_array($qrNode)) {
            $copyPaste = $this->stringOrNull($qrNode['emv'] ?? $qrNode['copy_paste'] ?? $qrNode['copyPaste'] ?? $qrNode['copy_and_paste'] ?? null);
            $image = $this->stringOrNull($qrNode['image'] ?? $qrNode['base64'] ?? null);
        } elseif (is_string($qrNode) && trim($qrNode) !== '') {
            if (str_starts_with(trim($qrNode), '000201')) {
                $copyPaste = trim($qrNode);
            } else {
                $image = trim($qrNode);
            }
        }

        if ($copyPaste === null) {
            $copyPaste = $this->stringOrNull(
                $pix['emv']
                ?? $pix['copy_paste']
                ?? $pix['copyPaste']
                ?? $pix['copy_and_paste']
                ?? $pix['payload']
                ?? null
            );
        }

        if ($image === null) {
            $image = $this->stringOrNull($pix['image'] ?? $pix['qrcode_image'] ?? null);
        }

        if ($image !== null && ! str_starts_with($image, 'http://') && ! str_starts_with($image, 'https://') && ! str_starts_with($image, 'data:')) {
            if (str_starts_with(trim($image), '000201')) {
                if ($copyPaste === null) {
                    $copyPaste = trim($image);
                }
                $image = null;
            }
        }

        return [$copyPaste, $image];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function mapApiStatusToInternal(?string $status): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }
        $s = strtoupper(trim($status));
        if ($s === 'APPROVED' || $s === 'PAID') {
            return 'paid';
        }
        if ($s === 'PENDING' || $s === 'PROCESSING') {
            return 'pending';
        }
        if ($s === 'EXPIRED' || $s === 'FAILED') {
            return 'cancelled';
        }
        if ($s === 'REFUNDED' || $s === 'REVERSED') {
            return 'refunded';
        }

        return strtolower($status);
    }

    private function normalizeCustomerPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        $digits = is_string($digits) ? $digits : '';
        if ($digits === '') {
            return '';
        }
        if (str_starts_with($digits, '55') && strlen($digits) > 11) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) >= 10 && strlen($digits) <= 11) {
            return $digits;
        }
        if (strlen($digits) >= 12) {
            return $digits;
        }

        return '';
    }

    private function normalizeDocument(string $document): string
    {
        $digits = preg_replace('/\D/', '', $document);
        $digits = is_string($digits) ? $digits : '';

        if (strlen($digits) === 11 || strlen($digits) === 14) {
            return $digits;
        }

        if (strlen($digits) > 14) {
            $digits = substr($digits, -14);
            if (strlen($digits) === 11 || strlen($digits) === 14) {
                return $digits;
            }
        }

        return '00000000000';
    }

    private function sanitizeName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?: '';
        $name = trim($name);
        if ($name === '') {
            return 'Cliente';
        }

        if (strlen($name) > 80) {
            return substr($name, 0, 80);
        }

        return $name;
    }

    private function sanitizeEmail(string $email): string
    {
        $email = trim($email);
        $email = preg_replace('/[\x00-\x1F\x7F]/u', '', $email) ?: '';
        $email = trim($email);
        if ($email === '') {
            return '';
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    private function baseUrl(array $credentials): string
    {
        $override = $credentials['base_url'] ?? null;
        if (is_string($override)) {
            $override = trim(str_replace(["\r", "\n", "\t"], '', $override), " \t\n\r\0\x0B/");
            if ($override !== '' && ! str_contains(strtolower($override), 'api.spacepag.com.br')) {
                return $override;
            }
        }

        return self::BASE_URL;
    }

    private function timeoutSeconds(array $credentials): int
    {
        $v = $credentials['timeout'] ?? null;
        $n = is_numeric($v) ? (int) $v : 25;

        return min(120, max(10, $n));
    }

    private function connectTimeoutSeconds(array $credentials): int
    {
        $v = $credentials['connect_timeout'] ?? null;
        $n = is_numeric($v) ? (int) $v : 10;

        return min(60, max(5, $n));
    }

    private function shouldForceIpv4ByDefault(array $credentials): bool
    {
        $v = $credentials['force_ipv4'] ?? null;
        if ($v === null) {
            return filter_var(getenv('GETFY_DOCKER') ?: false, FILTER_VALIDATE_BOOLEAN);
        }

        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    private function shouldDisableProxy(array $credentials): bool
    {
        $v = $credentials['disable_proxy'] ?? null;
        if ($v === null) {
            return filter_var(getenv('GETFY_DOCKER') ?: false, FILTER_VALIDATE_BOOLEAN);
        }

        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }
}
