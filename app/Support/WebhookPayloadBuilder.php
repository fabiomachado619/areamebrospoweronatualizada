<?php

namespace App\Support;

use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\StorageService;
use Illuminate\Support\Facades\URL;

class WebhookPayloadBuilder
{
    /**
     * @param  array<string, mixed>  $extras  pix, boleto, access, test flags, etc.
     * @return array<string, mixed>
     */
    public static function forOrderEvent(Order $order, array $extras = []): array
    {
        $order->loadMissing([
            'user',
            'product',
            'productOffer',
            'subscriptionPlan',
            'orderItems.product',
            'orderItems.productOffer',
            'orderItems.subscriptionPlan',
        ]);

        $session = CheckoutSession::query()
            ->where('order_id', $order->id)
            ->orderByDesc('id')
            ->first();

        $payload = [
            'order' => self::orderSnapshot($order),
            'customer' => self::customerFromOrder($order),
            'checkout_link' => self::checkoutLinkFromSlug($order->getCheckoutSlug()),
            'product' => self::productSnapshot($order->product),
            'offer' => self::offerSnapshot($order->productOffer),
            'subscription_plan' => self::planSnapshot($order->subscriptionPlan),
            'products' => self::lineItemsFromOrder($order),
            'payment' => self::paymentFromOrder($order),
            'tracking' => self::trackingFromOrder($order, $session),
        ];

        return array_merge($payload, $extras);
    }

    /**
     * @return array<string, mixed>
     */
    public static function forCartAbandoned(CheckoutSession $session): array
    {
        $session->loadMissing(['product', 'productOffer', 'subscriptionPlan']);

        $product = $session->product;
        $offer = $session->productOffer;
        $plan = $session->subscriptionPlan;

        $slug = $session->checkout_slug ?? $product?->checkout_slug ?? '';

        $line = [];
        if ($product) {
            $line[] = self::buildLineItem(
                product: $product,
                offer: $offer,
                plan: $plan,
                amount: (float) ($offer?->price ?? $plan?->price ?? $product->price ?? 0),
                currency: $offer?->getCurrencyOrDefault()
                    ?? $plan?->getCurrencyOrDefault()
                    ?? $product->getCurrencyOrDefault(),
                isMain: true,
                isOrderBump: false,
            );
        }

        return [
            'checkout_session' => [
                'id' => $session->id,
                'session_token' => $session->session_token,
                'step' => $session->step,
                'product_id' => $session->product_id,
                'product_offer_id' => $session->product_offer_id,
                'subscription_plan_id' => $session->subscription_plan_id,
                'checkout_slug' => $session->checkout_slug,
                'email' => $session->email,
                'name' => $session->name,
                'created_at' => $session->created_at?->toIso8601String(),
            ],
            'customer' => [
                'name' => $session->name ?? '',
                'email' => $session->email ?? '',
                'phone' => '',
                'cpf' => '',
            ],
            'checkout_link' => self::checkoutLinkFromSlug($slug),
            'product' => self::productSnapshot($product),
            'offer' => self::offerSnapshot($offer),
            'subscription_plan' => self::planSnapshot($plan),
            'products' => $line,
            'tracking' => self::trackingFromSession($session),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forSubscriptionEvent(Subscription $subscription): array
    {
        $subscription->loadMissing(['user', 'product', 'subscriptionPlan']);
        $lifecycle = app(\App\Services\SubscriptionLifecycleService::class);
        $accessUntil = $lifecycle->accessUntil($subscription);
        $renewableUntil = $lifecycle->renewableUntil($subscription);
        $periodEnd = $lifecycle->periodEnd($subscription);
        $daysOverdue = null;
        if ($periodEnd && $periodEnd->isPast()) {
            $daysOverdue = (int) $periodEnd->diffInDays(now()->startOfDay(), false);
            if ($daysOverdue < 0) {
                $daysOverdue = 0;
            }
        }

        $slug = $subscription->subscriptionPlan?->checkout_slug
            ?? $subscription->product?->checkout_slug
            ?? '';

        $plan = $subscription->subscriptionPlan;
        $product = $subscription->product;

        $lines = [];
        if ($product) {
            $lines[] = self::buildLineItem(
                product: $product,
                offer: null,
                plan: $plan,
                amount: (float) ($plan?->price ?? $product->price ?? 0),
                currency: $plan?->getCurrencyOrDefault() ?? $product->getCurrencyOrDefault(),
                isMain: true,
                isOrderBump: false,
            );
        }

        return [
            'subscription' => [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'effective_status' => $lifecycle->effectiveStatus($subscription),
                'product_id' => $subscription->product_id,
                'subscription_plan_id' => $subscription->subscription_plan_id,
                'user_id' => $subscription->user_id,
                'current_period_start' => $subscription->current_period_start?->toDateString(),
                'current_period_end' => $subscription->current_period_end?->toDateString(),
                'access_until' => $accessUntil?->toDateString(),
                'renewable_until' => $renewableUntil?->toDateString(),
                'days_overdue' => $daysOverdue,
                'cancelled_at' => $subscription->cancelled_at?->toIso8601String(),
                'gateway_subscription_id' => $subscription->gateway_subscription_id,
                'created_at' => $subscription->created_at?->toIso8601String(),
                'updated_at' => $subscription->updated_at?->toIso8601String(),
            ],
            'customer' => [
                'name' => $subscription->user?->name ?? '',
                'email' => $subscription->user?->email ?? '',
                'phone' => $subscription->user?->phone ?? '',
                'cpf' => '',
            ],
            'checkout_link' => self::checkoutLinkFromSlug($slug),
            'product' => self::productSnapshot($product),
            'offer' => null,
            'subscription_plan' => self::planSnapshot($plan),
            'products' => $lines,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function orderSnapshot(Order $order): array
    {
        return [
            'id' => $order->id,
            'tenant_id' => $order->tenant_id,
            'user_id' => $order->user_id,
            'product_id' => $order->product_id,
            'product_offer_id' => $order->product_offer_id,
            'subscription_plan_id' => $order->subscription_plan_id,
            'status' => $order->status,
            'amount' => (float) $order->amount,
            'currency' => $order->getCurrencyOrDefault(),
            'email' => $order->email,
            'cpf' => $order->cpf,
            'phone' => $order->phone,
            'coupon_code' => $order->coupon_code,
            'gateway' => $order->gateway,
            'gateway_id' => $order->gateway_id,
            'approved_manually' => (bool) $order->approved_manually,
            'is_renewal' => (bool) $order->is_renewal,
            'period_start' => $order->period_start?->toDateString(),
            'period_end' => $order->period_end?->toDateString(),
            'metadata' => $order->metadata ?? [],
            'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{name: string, email: string, phone: string, cpf: string}
     */
    private static function customerFromOrder(Order $order): array
    {
        return [
            'name' => $order->user?->name ?? '',
            'email' => $order->email ?? '',
            'phone' => $order->phone ?? '',
            'cpf' => $order->cpf ?? '',
        ];
    }

    private static function checkoutLinkFromSlug(string $slug): string
    {
        $slug = trim($slug);

        return $slug !== '' ? URL::route('checkout.show', ['slug' => $slug]) : '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function productSnapshot(?Product $product): ?array
    {
        if (! $product) {
            return null;
        }

        $imageUrl = null;
        if ($product->image) {
            $imageUrl = (new StorageService($product->tenant_id))->url($product->image);
        }

        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'checkout_slug' => $product->checkout_slug,
            'type' => $product->type,
            'billing_type' => $product->billing_type,
            'image_url' => $imageUrl,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function offerSnapshot(?ProductOffer $offer): ?array
    {
        if (! $offer) {
            return null;
        }

        return [
            'id' => $offer->id,
            'product_id' => $offer->product_id,
            'name' => $offer->name,
            'price' => (float) $offer->price,
            'currency' => $offer->getCurrencyOrDefault(),
            'checkout_slug' => $offer->checkout_slug,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function planSnapshot(?SubscriptionPlan $plan): ?array
    {
        if (! $plan) {
            return null;
        }

        return [
            'id' => $plan->id,
            'product_id' => $plan->product_id,
            'name' => $plan->name,
            'price' => (float) $plan->price,
            'currency' => $plan->getCurrencyOrDefault(),
            'interval' => $plan->interval,
            'checkout_slug' => $plan->checkout_slug,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function lineItemsFromOrder(Order $order): array
    {
        $currency = $order->getCurrencyOrDefault();

        if ($order->orderItems->isNotEmpty()) {
            $lines = [];
            foreach ($order->orderItems as $item) {
                $product = $item->product;
                if (! $product) {
                    continue;
                }
                $isMainLine = (int) ($item->position ?? 0) === 0;
                $lines[] = self::buildLineItem(
                    product: $product,
                    offer: $item->productOffer,
                    plan: $item->subscriptionPlan,
                    amount: (float) $item->amount,
                    currency: $currency,
                    isMain: $isMainLine,
                    isOrderBump: ! $isMainLine,
                );
            }

            if ($lines !== []) {
                return $lines;
            }
        }

        $product = $order->product;
        if (! $product) {
            return [];
        }

        return [
            self::buildLineItem(
                product: $product,
                offer: $order->productOffer,
                plan: $order->subscriptionPlan,
                amount: (float) $order->amount,
                currency: $currency,
                isMain: true,
                isOrderBump: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildLineItem(
        Product $product,
        ?ProductOffer $offer,
        ?SubscriptionPlan $plan,
        float $amount,
        string $currency,
        bool $isMain,
        bool $isOrderBump,
    ): array {
        return [
            'product_id' => $product->id,
            'name' => $product->name,
            'type' => $product->type,
            'offer' => self::offerSnapshot($offer),
            'subscription_plan' => self::planSnapshot($plan),
            'amount' => $amount,
            'currency' => $currency,
            'quantity' => 1,
            'is_main' => $isMain,
            'is_order_bump' => $isOrderBump,
        ];
    }

    /**
     * @return array{method: string, gateway: ?string, gateway_transaction_id: ?string}
     */
    private static function paymentFromOrder(Order $order): array
    {
        return [
            'method' => $order->checkoutPaymentMethod(),
            'gateway' => $order->gateway,
            'gateway_transaction_id' => $order->gateway_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function trackingFromOrder(Order $order, ?CheckoutSession $session): array
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];

        $tracking = [
            'utm_source' => self::stringOrNull($meta['utm_source'] ?? null),
            'utm_medium' => self::stringOrNull($meta['utm_medium'] ?? null),
            'utm_campaign' => self::stringOrNull($meta['utm_campaign'] ?? null),
            'affiliate_code' => $order->affiliateCode(),
            'sale_channel' => $order->saleChannel(),
        ];

        if ($session) {
            foreach (['utm_source', 'utm_medium', 'utm_campaign'] as $key) {
                if ($tracking[$key] === null) {
                    $tracking[$key] = self::stringOrNull($session->{$key} ?? null);
                }
            }
            $sessionMeta = is_array($session->tracking_metadata) ? $session->tracking_metadata : [];
            foreach ($sessionMeta as $k => $v) {
                if (! is_string($k) || $k === '') {
                    continue;
                }
                if (! isset($tracking[$k]) || $tracking[$k] === null) {
                    $tracking[$k] = is_scalar($v) ? trim((string) $v) : null;
                    if ($tracking[$k] === '') {
                        $tracking[$k] = null;
                    }
                }
            }
        }

        return $tracking;
    }

    /**
     * @return array<string, mixed>
     */
    private static function trackingFromSession(CheckoutSession $session): array
    {
        $tracking = [
            'utm_source' => self::stringOrNull($session->utm_source),
            'utm_medium' => self::stringOrNull($session->utm_medium),
            'utm_campaign' => self::stringOrNull($session->utm_campaign),
            'affiliate_code' => null,
            'sale_channel' => null,
        ];

        $sessionMeta = is_array($session->tracking_metadata) ? $session->tracking_metadata : [];
        foreach ($sessionMeta as $k => $v) {
            if (! is_string($k) || $k === '') {
                continue;
            }
            if (is_scalar($v)) {
                $str = trim((string) $v);
                if ($str !== '') {
                    $tracking[$k] = $str;
                }
            }
        }

        $ref = $sessionMeta['affiliate_ref'] ?? $sessionMeta['ref'] ?? null;
        if (is_string($ref) && trim($ref) !== '') {
            $tracking['affiliate_code'] = AffiliateAttribution::normalizeRef($ref);
        }

        return $tracking;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $str = trim((string) $value);

        return $str !== '' ? $str : null;
    }

    /**
     * Payload de exemplo para teste manual no painel de integrações.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function sampleTestPayload(string $eventSlug, array $context = []): array
    {
        $checkoutLink = rtrim((string) config('app.url'), '/').'/c/exemplo-checkout';
        $productId = $context['product_id'] ?? 'prod-exemplo-uuid';
        $offerId = $context['offer_id'] ?? 1;

        $base = [
            'test' => true,
            'message' => 'Este é um evento de teste disparado manualmente.',
            'webhook_name' => $context['webhook_name'] ?? 'Webhook de teste',
            'webhook_id' => $context['webhook_id'] ?? 0,
            'order' => [
                'id' => 90001,
                'product_id' => $productId,
                'product_offer_id' => $offerId,
                'subscription_plan_id' => null,
                'status' => $eventSlug === 'pedido_pago' ? 'completed' : 'pending',
                'amount' => 197.0,
                'currency' => 'BRL',
                'email' => 'exemplo@email.com',
                'coupon_code' => null,
                'gateway' => 'cajupay',
                'gateway_id' => 'tx_exemplo_123',
                'metadata' => ['checkout_payment_method' => 'pix'],
                'created_at' => now()->toIso8601String(),
                'updated_at' => now()->toIso8601String(),
            ],
            'customer' => [
                'name' => 'Cliente Exemplo',
                'email' => 'exemplo@email.com',
                'phone' => '11999999999',
                'cpf' => '12345678900',
            ],
            'checkout_link' => $checkoutLink,
            'product' => [
                'id' => $productId,
                'name' => 'MeuLink - Full Anual',
                'slug' => 'meulink-full',
                'checkout_slug' => 'exemplo-checkout',
                'type' => 'area_membros',
                'billing_type' => 'one_time',
                'image_url' => null,
            ],
            'offer' => [
                'id' => $offerId,
                'product_id' => $productId,
                'name' => 'Oferta principal',
                'price' => 197.0,
                'currency' => 'BRL',
                'checkout_slug' => null,
            ],
            'subscription_plan' => null,
            'products' => [
                [
                    'product_id' => $productId,
                    'name' => 'MeuLink - Full Anual',
                    'type' => 'area_membros',
                    'offer' => [
                        'id' => $offerId,
                        'product_id' => $productId,
                        'name' => 'Oferta principal',
                        'price' => 197.0,
                        'currency' => 'BRL',
                        'checkout_slug' => null,
                    ],
                    'subscription_plan' => null,
                    'amount' => 197.0,
                    'currency' => 'BRL',
                    'quantity' => 1,
                    'is_main' => true,
                    'is_order_bump' => false,
                ],
            ],
            'payment' => [
                'method' => 'pix',
                'gateway' => 'cajupay',
                'gateway_transaction_id' => 'tx_exemplo_123',
            ],
            'tracking' => [
                'utm_source' => 'instagram',
                'utm_medium' => 'social',
                'utm_campaign' => 'lancamento',
                'affiliate_code' => null,
                'sale_channel' => 'producer',
            ],
        ];

        if ($eventSlug === 'pix_gerado') {
            $base['pix'] = [
                'qrcode' => 'data:image/png;base64,iVBORw0KGgo=',
                'copy_paste' => '00020126580014br.gov.bcb.pix...',
                'transaction_id' => 'txid-exemplo-teste',
            ];
        }

        if ($eventSlug === 'boleto_gerado') {
            $base['boleto'] = [
                'amount' => 197.0,
                'expire_at' => now()->addDays(3)->toDateString(),
                'barcode' => '23793.38128 60000.000003 00000.000400 1 84370000019700',
                'pdf_url' => $checkoutLink,
            ];
        }

        if ($eventSlug === 'carrinho_abandonado') {
            unset($base['order'], $base['payment']);
            $base['checkout_session'] = [
                'id' => 1,
                'session_token' => 'sess-exemplo',
                'step' => 'form_filled',
                'product_id' => $productId,
                'product_offer_id' => $offerId,
                'email' => 'exemplo@email.com',
                'name' => 'Cliente Exemplo',
            ];
        }

        if (str_starts_with($eventSlug, 'assinatura_')) {
            unset($base['order'], $base['offer'], $base['payment']);
            $base['subscription'] = [
                'id' => 1,
                'status' => 'active',
                'product_id' => $productId,
                'subscription_plan_id' => 1,
            ];
            $base['subscription_plan'] = [
                'id' => 1,
                'product_id' => $productId,
                'name' => 'Plano mensal',
                'price' => 49.9,
                'currency' => 'BRL',
                'interval' => 'monthly',
                'checkout_slug' => 'exemplo-checkout',
            ];
        }

        return $base;
    }
}
