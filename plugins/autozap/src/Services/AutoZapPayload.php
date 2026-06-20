<?php

namespace Plugins\AutoZap\Services;

use App\Events\BoletoGenerated;
use App\Events\CartAbandoned;
use App\Events\AccessDeliveryReady;
use App\Events\MemberAccessGranted;
use App\Events\OrderCancelled;
use App\Events\OrderCompleted;
use App\Events\OrderPending;
use App\Events\OrderRefunded;
use App\Events\OrderRejected;
use App\Events\PixGenerated;
use App\Events\SubscriptionCancelled;
use App\Events\SubscriptionCreated;
use App\Events\SubscriptionPastDue;
use App\Events\SubscriptionRenewed;

class AutoZapPayload
{
    /**
     * Create a normalized payload for templates/conditions.
     *
     * @return array<string, mixed>
     */
    public static function fromEvent(object $event): array
    {
        $base = [
            'event_class' => $event::class,
            'customer' => [
                'name' => null,
                'email' => null,
                'phone' => null,
                'cpf' => null,
            ],
            'checkout_link' => null,
        ];

        if ($event instanceof OrderPending || $event instanceof OrderCompleted
            || $event instanceof OrderRejected || $event instanceof OrderCancelled
            || $event instanceof OrderRefunded || $event instanceof PixGenerated
            || $event instanceof BoletoGenerated) {
            $order = $event->order;
            $order->loadMissing(['user', 'product', 'productOffer', 'subscriptionPlan']);
            $base['order'] = $order->toArray();
            $base['customer'] = [
                'name' => $order->user?->name,
                'email' => $order->email,
                // Some orders don't have phone persisted; fallback to user phone when available.
                'phone' => $order->phone ?: $order->user?->phone,
                'cpf' => $order->cpf,
            ];
            $base['checkout_link'] = $order->getCheckoutSlug()
                ? url('/c/' . $order->getCheckoutSlug())
                : null;
        }

        if ($event instanceof AccessDeliveryReady) {
            $order = $event->order;
            $order->loadMissing(['user', 'product', 'productOffer', 'subscriptionPlan']);
            $base['order'] = $order->toArray();
            $base['customer'] = [
                'name' => $order->user?->name,
                'email' => $order->email,
                'phone' => $order->phone ?: $order->user?->phone,
                'cpf' => $order->cpf,
            ];
            $base['checkout_link'] = $order->getCheckoutSlug()
                ? url('/c/' . $order->getCheckoutSlug())
                : null;
            $base['access'] = is_array($event->access) ? $event->access : [];
        }

        if ($event instanceof MemberAccessGranted) {
            $user = $event->user;
            $product = $event->product;
            $user->loadMissing([]);
            $product->loadMissing([]);
            $base['order'] = [
                'email' => $user->email,
                'phone' => $user->phone,
                'product' => $product->toArray(),
            ];
            $base['customer'] = [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'cpf' => null,
            ];
            $base['checkout_link'] = is_array($event->access) ? ($event->access['link'] ?? null) : null;
            $base['access'] = is_array($event->access) ? $event->access : [];
        }

        if ($event instanceof PixGenerated) {
            $base['pix'] = [
                'qrcode' => $event->pixData['qrcode'] ?? null,
                'copy_paste' => $event->pixData['copy_paste'] ?? null,
                'transaction_id' => $event->pixData['transaction_id'] ?? null,
            ];
        }

        if ($event instanceof BoletoGenerated) {
            $base['boleto'] = [
                'amount' => $event->boletoData['amount'] ?? null,
                'expire_at' => $event->boletoData['expire_at'] ?? null,
                'barcode' => $event->boletoData['barcode'] ?? null,
                'pdf_url' => $event->boletoData['pdf_url'] ?? null,
            ];
        }

        if ($event instanceof CartAbandoned) {
            $s = $event->checkoutSession;
            $s->loadMissing('product');
            $base['checkout_session'] = $s->toArray();
            $base['customer'] = [
                'name' => $s->name ?? null,
                'email' => $s->email ?? null,
                'phone' => null,
                'cpf' => null,
            ];
            $slug = $s->checkout_slug ?? $s->product?->checkout_slug ?? null;
            $base['checkout_link'] = $slug ? url('/c/' . $slug) : null;
        }

        if ($event instanceof SubscriptionCreated || $event instanceof SubscriptionRenewed
            || $event instanceof SubscriptionCancelled || $event instanceof SubscriptionPastDue) {
            $sub = $event->subscription;
            $sub->loadMissing(['user', 'product', 'subscriptionPlan']);
            $base['subscription'] = $sub->toArray();
            $base['customer'] = [
                'name' => $sub->user?->name,
                'email' => $sub->user?->email,
                'phone' => null,
                'cpf' => null,
            ];
            $slug = $sub->subscriptionPlan?->checkout_slug ?? $sub->product?->checkout_slug ?? null;
            $base['checkout_link'] = $slug ? url('/c/' . $slug) : null;
        }

        return $base;
    }

    /**
     * Resolve customer phone as digits (best-effort for WhatsApp).
     */
    public static function resolvePhone(array $payload): string
    {
        $raw = $payload['customer']['phone']
            ?? $payload['order']['phone']
            ?? $payload['order']['user']['phone']
            ?? '';
        if (! is_string($raw)) return '';
        $digits = preg_replace('/\\D+/', '', $raw) ?: '';
        // If user stored phone without country code, it's ambiguous; we keep digits as-is.
        return $digits;
    }
}

