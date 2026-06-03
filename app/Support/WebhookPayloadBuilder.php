<?php

namespace App\Support;

use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\URL;

class WebhookPayloadBuilder
{
    /** @var list<string> */
    private const TRACKING_KEYS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'fbclid',
        'gclid',
        'msclkid',
        'src',
        'sck',
    ];

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
            'payment' => self::paymentFromOrder($order),
            'tracking' => self::trackingFromOrder($order, $session),
        ];

        $bumps = self::orderBumpsFromOrder($order);
        if ($bumps !== []) {
            $payload['order_bumps'] = $bumps;
        }

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

        return [
            'checkout_session' => [
                'id' => $session->id,
                'email' => $session->email,
                'name' => $session->name,
                'created_at' => $session->created_at?->toIso8601String(),
            ],
            'customer' => [
                'name' => $session->name ?? '',
                'email' => $session->email ?? '',
            ],
            'checkout_link' => self::checkoutLinkFromSlug($slug),
            'product' => self::productSnapshot($product),
            'offer' => self::offerSnapshot($offer),
            'subscription_plan' => self::planSnapshot($plan),
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

        return [
            'subscription' => [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'effective_status' => $lifecycle->effectiveStatus($subscription),
                'current_period_start' => $subscription->current_period_start?->toDateString(),
                'current_period_end' => $subscription->current_period_end?->toDateString(),
                'access_until' => $accessUntil?->toDateString(),
                'renewable_until' => $renewableUntil?->toDateString(),
                'days_overdue' => $daysOverdue,
                'cancelled_at' => $subscription->cancelled_at?->toIso8601String(),
            ],
            'customer' => [
                'name' => $subscription->user?->name ?? '',
                'email' => $subscription->user?->email ?? '',
                'phone' => $subscription->user?->phone ?? '',
            ],
            'checkout_link' => self::checkoutLinkFromSlug($slug),
            'product' => self::productSnapshot($subscription->product),
            'subscription_plan' => self::planSnapshot($subscription->subscriptionPlan),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function orderSnapshot(Order $order): array
    {
        $snapshot = [
            'id' => $order->id,
            'status' => $order->status,
            'amount' => (float) $order->amount,
            'currency' => $order->getCurrencyOrDefault(),
            'coupon_code' => $order->coupon_code,
            'is_renewal' => (bool) $order->is_renewal,
            'created_at' => $order->created_at?->toIso8601String(),
        ];

        if ($order->period_start || $order->period_end) {
            $snapshot['period_start'] = $order->period_start?->toDateString();
            $snapshot['period_end'] = $order->period_end?->toDateString();
        }

        return $snapshot;
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

        return [
            'id' => $product->id,
            'name' => $product->name,
            'type' => $product->type,
            'billing_type' => $product->billing_type,
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
            'name' => $offer->name,
            'price' => (float) $offer->price,
            'currency' => $offer->getCurrencyOrDefault(),
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
            'name' => $plan->name,
            'price' => (float) $plan->price,
            'currency' => $plan->getCurrencyOrDefault(),
            'interval' => $plan->interval,
        ];
    }

    /**
     * Linhas extras (order bumps) — o produto principal fica em product/offer/subscription_plan.
     *
     * @return list<array<string, mixed>>
     */
    private static function orderBumpsFromOrder(Order $order): array
    {
        if ($order->orderItems->isEmpty()) {
            return [];
        }

        $currency = $order->getCurrencyOrDefault();
        $lines = [];

        foreach ($order->orderItems as $item) {
            if ((int) ($item->position ?? 0) === 0) {
                continue;
            }
            $product = $item->product;
            if (! $product) {
                continue;
            }
            $lines[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'amount' => (float) $item->amount,
                'currency' => $currency,
            ];
        }

        return $lines;
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
        $tracking = self::pickTrackingFields($meta);

        foreach (['utm_source', 'utm_medium', 'utm_campaign'] as $key) {
            if (($tracking[$key] ?? null) === null) {
                $tracking[$key] = self::stringOrNull($meta[$key] ?? null);
            }
        }

        if ($tracking['affiliate_code'] === null) {
            $tracking['affiliate_code'] = $order->affiliateCode();
        }
        if ($tracking['sale_channel'] === null) {
            $tracking['sale_channel'] = $order->saleChannel();
        }

        if ($session) {
            $sessionFields = self::pickTrackingFields([
                'utm_source' => $session->utm_source,
                'utm_medium' => $session->utm_medium,
                'utm_campaign' => $session->utm_campaign,
                ...(is_array($session->tracking_metadata) ? $session->tracking_metadata : []),
            ]);
            foreach ($sessionFields as $key => $value) {
                if ($value !== null && ($tracking[$key] ?? null) === null) {
                    $tracking[$key] = $value;
                }
            }
        }

        return self::filterEmptyTracking($tracking);
    }

    /**
     * @return array<string, mixed>
     */
    private static function trackingFromSession(CheckoutSession $session): array
    {
        $tracking = self::pickTrackingFields([
            'utm_source' => $session->utm_source,
            'utm_medium' => $session->utm_medium,
            'utm_campaign' => $session->utm_campaign,
            ...(is_array($session->tracking_metadata) ? $session->tracking_metadata : []),
        ]);

        $sessionMeta = is_array($session->tracking_metadata) ? $session->tracking_metadata : [];
        $ref = $sessionMeta['affiliate_ref'] ?? $sessionMeta['ref'] ?? null;
        if (is_string($ref) && trim($ref) !== '') {
            $tracking['affiliate_code'] = AffiliateAttribution::normalizeRef($ref);
        }

        return self::filterEmptyTracking($tracking);
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, ?string>
     */
    private static function pickTrackingFields(array $source): array
    {
        $tracking = [
            'affiliate_code' => null,
            'sale_channel' => null,
        ];

        foreach (self::TRACKING_KEYS as $key) {
            $tracking[$key] = self::stringOrNull($source[$key] ?? null);
        }

        return $tracking;
    }

    /**
     * @param  array<string, mixed>  $tracking
     * @return array<string, mixed>
     */
    private static function filterEmptyTracking(array $tracking): array
    {
        return array_filter(
            $tracking,
            fn ($value) => $value !== null && $value !== ''
        );
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
                'status' => $eventSlug === 'pedido_pago' ? 'completed' : 'pending',
                'amount' => 197.0,
                'currency' => 'BRL',
                'coupon_code' => null,
                'is_renewal' => false,
                'created_at' => now()->toIso8601String(),
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
                'type' => 'area_membros',
                'billing_type' => 'one_time',
            ],
            'offer' => [
                'id' => $offerId,
                'name' => 'Oferta principal',
                'price' => 197.0,
                'currency' => 'BRL',
            ],
            'subscription_plan' => null,
            'payment' => [
                'method' => 'pix',
                'gateway' => 'cajupay',
                'gateway_transaction_id' => 'tx_exemplo_123',
            ],
            'tracking' => [
                'utm_source' => 'instagram',
                'utm_medium' => 'social',
                'utm_campaign' => 'lancamento',
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
                'email' => 'exemplo@email.com',
                'name' => 'Cliente Exemplo',
                'created_at' => now()->toIso8601String(),
            ];
        }

        if (str_starts_with($eventSlug, 'assinatura_')) {
            unset($base['order'], $base['offer'], $base['payment']);
            $base['subscription'] = [
                'id' => 1,
                'status' => 'active',
                'effective_status' => 'active',
                'current_period_start' => now()->toDateString(),
                'current_period_end' => now()->addMonth()->toDateString(),
            ];
            $base['subscription_plan'] = [
                'id' => 1,
                'name' => 'Plano mensal',
                'price' => 49.9,
                'currency' => 'BRL',
                'interval' => 'monthly',
            ];
        }

        return $base;
    }
}
