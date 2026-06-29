<?php

namespace App\Services;

class WebhookPayloadNormalizer
{
    public const PLATFORM_NOTASCAST = 'notascast';

    public const PLATFORM_POWERON = 'poweron';

    public const PLATFORM_KIWIFY = 'kiwify';

    public const PLATFORM_WIAPY = 'wiapy';

    public const PLATFORM_HOTMART = 'hotmart';

    public const PLATFORM_GG_CHECKOUT = 'gg_checkout';

    public const PLATFORM_CANONICAL = 'canonical';

    /** @var list<string> */
    public const PROBE_EVENTS = [
        'test',
        'ping',
        'webhook.test',
        'healthcheck',
        'health_check',
        'validation',
    ];

    /**
     * Detecta apenas probes explícitos (body vazio ou evento de teste conhecido).
     * Não infere teste a partir de payload parcial de nenhuma plataforma.
     *
     * @param  array<string, mixed>  $rawPayload
     */
    public function isProbePayload(array $rawPayload): bool
    {
        if ($rawPayload === []) {
            return true;
        }

        $root = $rawPayload;
        $body = isset($rawPayload['body']) && is_array($rawPayload['body']) ? $rawPayload['body'] : $rawPayload;

        foreach ([$root, $body] as $source) {
            foreach (['event', 'type'] as $field) {
                $value = strtolower(trim((string) ($source[$field] ?? '')));
                if ($value !== '' && in_array($value, self::PROBE_EVENTS, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalize(array $payload): array
    {
        $root = $payload;
        $body = isset($payload['body']) && is_array($payload['body']) ? $payload['body'] : $payload;

        $platform = $this->detectPlatform($root, $body);

        $normalized = match ($platform) {
            self::PLATFORM_KIWIFY => $this->normalizeKiwify($body, $payload),
            self::PLATFORM_POWERON => $this->normalizePowerOn($body, $payload),
            self::PLATFORM_HOTMART => $this->normalizeHotmart($body, $payload),
            self::PLATFORM_WIAPY => $this->normalizeWiapy($root, $body, $payload),
            self::PLATFORM_NOTASCAST => $this->normalizeNotascast($body, $payload),
            self::PLATFORM_GG_CHECKOUT => $this->normalizeGgCheckout($body, $payload),
            default => $this->normalizeCanonical($root, $body, $payload),
        };

        return $this->finalizeNormalized($normalized);
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array<string, mixed>
     */
    public function toEnrollmentRequest(array $normalized): array
    {
        $raw = is_array($normalized['raw_payload'] ?? null) ? $normalized['raw_payload'] : [];
        $event = $this->nullableLower($normalized['event'] ?? null) ?? '';

        return [
            'name' => $normalized['name'],
            'email' => $normalized['email'],
            'phone' => $normalized['phone'],
            'document' => $normalized['document'],
            'platform' => $normalized['platform'],
            'event' => $event !== '' ? $event : 'purchase_approved',
            'transaction_id' => $normalized['transaction_id'],
            'status' => $normalized['status'],
            'external_product_id' => $normalized['product_id'],
            'course_id' => $this->nullableString($raw['course_id'] ?? null),
            'hub_id' => $this->nullableString($raw['hub_id'] ?? null),
            'send_access_email' => array_key_exists('send_access_email', $raw)
                ? (bool) $raw['send_access_email']
                : true,
        ];
    }

    /**
     * @param  array<string, mixed>  $root
     * @param  array<string, mixed>  $body
     */
    private function detectPlatform(array $root, array $body): string
    {
        if (isset($body['Customer']) && isset($body['webhook_event_type'])) {
            return self::PLATFORM_KIWIFY;
        }

        if (isset($body['payload']['customer']) && is_array($body['payload']['customer'])) {
            return self::PLATFORM_POWERON;
        }

        if (isset($body['data']['buyer']) && is_array($body['data']['buyer']) && isset($body['event'])) {
            return self::PLATFORM_HOTMART;
        }

        if ($this->looksLikeWiapy($root) || $this->looksLikeWiapy($body)) {
            return self::PLATFORM_WIAPY;
        }

        if ($this->looksLikeGgCheckout($body)) {
            return self::PLATFORM_GG_CHECKOUT;
        }

        if ($this->looksLikeNotascast($body)) {
            return self::PLATFORM_NOTASCAST;
        }

        return self::PLATFORM_CANONICAL;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function looksLikeWiapy(array $data): bool
    {
        return isset($data['data']['payment']) && is_array($data['data']['payment'])
            && isset($data['data']['customer']) && is_array($data['data']['customer']);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function looksLikeGgCheckout(array $body): bool
    {
        return isset($body['customer']) && is_array($body['customer'])
            && isset($body['payment']) && is_array($body['payment'])
            && isset($body['product']) && is_array($body['product']);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function looksLikeNotascast(array $body): bool
    {
        if (! isset($body['email']) || trim((string) $body['email']) === '') {
            return false;
        }

        return isset($body['whatsapp']) || (isset($body['name']) && ! isset($body['event']) && ! isset($body['payload']));
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $rawPayload
     * @return array<string, mixed>
     */
    private function normalizeKiwify(array $body, array $rawPayload): array
    {
        $customer = is_array($body['Customer'] ?? null) ? $body['Customer'] : [];
        $product = is_array($body['Product'] ?? null) ? $body['Product'] : [];

        return [
            'name' => $this->nullableString($customer['full_name'] ?? null),
            'email' => $this->nullableString($customer['email'] ?? null),
            'phone' => $this->nullableString($customer['mobile'] ?? null),
            'document' => $this->nullableString($customer['CPF'] ?? $customer['cpf'] ?? null),
            'platform' => self::PLATFORM_KIWIFY,
            'event' => $this->nullableString($body['webhook_event_type'] ?? null),
            'status' => $this->nullableString($body['order_status'] ?? null),
            'transaction_id' => $this->nullableString($body['order_id'] ?? null),
            'product_id' => $this->nullableString($product['product_id'] ?? null),
            'product_name' => $this->nullableString($product['product_name'] ?? null),
            'raw_payload' => $rawPayload,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $rawPayload
     * @return array<string, mixed>
     */
    private function normalizePowerOn(array $body, array $rawPayload): array
    {
        $inner = is_array($body['payload'] ?? null) ? $body['payload'] : [];
        $customer = is_array($inner['customer'] ?? null) ? $inner['customer'] : [];
        $order = is_array($inner['order'] ?? null) ? $inner['order'] : [];
        $payment = is_array($inner['payment'] ?? null) ? $inner['payment'] : [];
        $product = is_array($inner['product'] ?? null) ? $inner['product'] : [];

        $status = $this->nullableString($inner['status'] ?? $order['status'] ?? null);
        $transactionId = $this->nullableString($payment['gateway_transaction_id'] ?? null)
            ?? $this->nullableString($order['id'] ?? null);

        return [
            'name' => $this->nullableString($customer['name'] ?? null),
            'email' => $this->nullableString($customer['email'] ?? null),
            'phone' => $this->nullableString($customer['phone'] ?? null),
            'document' => $this->nullableString($customer['docNumber'] ?? null),
            'platform' => self::PLATFORM_POWERON,
            'event' => $this->nullableString($body['event'] ?? $rawPayload['event'] ?? null),
            'status' => $status,
            'transaction_id' => $transactionId,
            'product_id' => $this->nullableString($product['id'] ?? null),
            'product_name' => $this->nullableString($product['name'] ?? null),
            'raw_payload' => $rawPayload,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $rawPayload
     * @return array<string, mixed>
     */
    private function normalizeHotmart(array $body, array $rawPayload): array
    {
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $buyer = is_array($data['buyer'] ?? null) ? $data['buyer'] : [];
        $purchase = is_array($data['purchase'] ?? null) ? $data['purchase'] : [];
        $product = is_array($data['product'] ?? null) ? $data['product'] : [];

        $phone = $this->nullableString($buyer['checkout_phone'] ?? null)
            ?? $this->nullableString($buyer['checkout_phone_code'] ?? null);

        $productId = $this->nullableString($product['ucode'] ?? null)
            ?? $this->nullableString($product['id'] ?? null);

        return [
            'name' => $this->nullableString($buyer['name'] ?? null),
            'email' => $this->nullableString($buyer['email'] ?? null),
            'phone' => $phone,
            'document' => $this->nullableString($buyer['document'] ?? null),
            'platform' => self::PLATFORM_HOTMART,
            'event' => $this->nullableString($body['event'] ?? null),
            'status' => $this->nullableString($purchase['status'] ?? null),
            'transaction_id' => $this->nullableString($purchase['transaction'] ?? null),
            'product_id' => $productId,
            'product_name' => $this->nullableString($product['name'] ?? null),
            'raw_payload' => $rawPayload,
        ];
    }

    /**
     * @param  array<string, mixed>  $root
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $rawPayload
     * @return array<string, mixed>
     */
    private function normalizeWiapy(array $root, array $body, array $rawPayload): array
    {
        $source = $this->looksLikeWiapy($root) ? $root : $body;
        $data = is_array($source['data'] ?? null) ? $source['data'] : [];
        $payment = is_array($data['payment'] ?? null) ? $data['payment'] : [];
        $customer = is_array($data['customer'] ?? null) ? $data['customer'] : [];
        $checkout = is_array($data['checkout'] ?? null) ? $data['checkout'] : [];
        $products = is_array($data['products'] ?? null) ? $data['products'] : [];
        $firstProduct = is_array($products[0] ?? null) ? $products[0] : [];

        $productId = $this->nullableString($firstProduct['id'] ?? null)
            ?? $this->nullableString($checkout['id'] ?? null);
        $productName = $this->nullableString($firstProduct['title'] ?? null)
            ?? $this->nullableString($checkout['title'] ?? null);

        return [
            'name' => $this->nullableString($customer['name'] ?? null),
            'email' => $this->nullableString($customer['email'] ?? null),
            'phone' => $this->nullableString($customer['mobile_phone'] ?? null),
            'document' => $this->nullableString($customer['document'] ?? null),
            'platform' => self::PLATFORM_WIAPY,
            'event' => $this->nullableString($payment['type'] ?? 'payment'),
            'status' => $this->nullableString($payment['status'] ?? null),
            'transaction_id' => $this->nullableString($payment['id'] ?? null),
            'product_id' => $productId,
            'product_name' => $productName,
            'raw_payload' => $rawPayload,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $rawPayload
     * @return array<string, mixed>
     */
    private function normalizeNotascast(array $body, array $rawPayload): array
    {
        return [
            'name' => $this->nullableString($body['name'] ?? null),
            'email' => $this->nullableString($body['email'] ?? null),
            'phone' => $this->nullableString($body['whatsapp'] ?? null),
            'document' => null,
            'platform' => self::PLATFORM_NOTASCAST,
            'event' => 'lead_created',
            'status' => 'approved',
            'transaction_id' => null,
            'product_id' => null,
            'product_name' => null,
            'raw_payload' => $rawPayload,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $rawPayload
     * @return array<string, mixed>
     */
    private function normalizeGgCheckout(array $body, array $rawPayload): array
    {
        $customer = is_array($body['customer'] ?? null) ? $body['customer'] : [];
        $payment = is_array($body['payment'] ?? null) ? $body['payment'] : [];
        $product = is_array($body['product'] ?? null) ? $body['product'] : [];

        return [
            'name' => $this->nullableString($customer['name'] ?? null),
            'email' => $this->nullableString($customer['email'] ?? null),
            'phone' => $this->nullableString($customer['phone'] ?? null),
            'document' => $this->nullableString($customer['document'] ?? null),
            'platform' => self::PLATFORM_GG_CHECKOUT,
            'event' => $this->nullableString($body['event'] ?? null),
            'status' => $this->nullableString($payment['status'] ?? null),
            'transaction_id' => $this->nullableString($payment['id'] ?? null),
            'product_id' => $this->nullableString($product['id'] ?? null),
            'product_name' => $this->nullableString($product['title'] ?? null),
            'raw_payload' => $rawPayload,
        ];
    }

    /**
     * @param  array<string, mixed>  $root
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $rawPayload
     * @return array<string, mixed>
     */
    private function normalizeCanonical(array $root, array $body, array $rawPayload): array
    {
        $source = $this->hasFlatEnrollmentFields($root) ? $root : $body;

        return [
            'name' => $this->nullableString($source['name'] ?? null),
            'email' => $this->nullableString($source['email'] ?? null),
            'phone' => $this->nullableString($source['phone'] ?? null),
            'document' => $this->nullableString($source['document'] ?? null),
            'platform' => $this->nullableString($source['platform'] ?? null),
            'event' => $this->nullableString($source['event'] ?? null),
            'status' => $this->nullableString($source['status'] ?? null),
            'transaction_id' => $this->nullableString($source['transaction_id'] ?? null),
            'product_id' => $this->nullableString($source['external_product_id'] ?? $source['product_id'] ?? null),
            'product_name' => $this->nullableString($source['product_name'] ?? null),
            'raw_payload' => $rawPayload,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hasFlatEnrollmentFields(array $data): bool
    {
        return isset($data['email']) || isset($data['event']);
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array<string, mixed>
     */
    private function finalizeNormalized(array $normalized): array
    {
        $email = $this->nullableString($normalized['email'] ?? null);
        if ($email !== null) {
            $email = strtolower($email);
        }

        $name = $this->nullableString($normalized['name'] ?? null);
        if (($name === null || $name === '') && $email !== null) {
            $name = $email;
        }

        return [
            'name' => $name,
            'email' => $email,
            'phone' => $this->nullableString($normalized['phone'] ?? null),
            'document' => $this->nullableString($normalized['document'] ?? null),
            'platform' => $this->nullableString($normalized['platform'] ?? null),
            'event' => $this->nullableLower($normalized['event'] ?? null),
            'status' => $this->nullableLower($normalized['status'] ?? null),
            'transaction_id' => $this->nullableString($normalized['transaction_id'] ?? null),
            'product_id' => $this->nullableString($normalized['product_id'] ?? null),
            'product_name' => $this->nullableString($normalized['product_name'] ?? null),
            'raw_payload' => $normalized['raw_payload'] ?? [],
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

    private function nullableLower(mixed $value): ?string
    {
        $string = $this->nullableString($value);

        return $string === null ? null : strtolower($string);
    }
}
