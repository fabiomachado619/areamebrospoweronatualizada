<?php

namespace App\Listeners;

use App\Events\AccessDeliveryReady;
use App\Events\BoletoGenerated;
use App\Events\CartAbandoned;
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
use App\Jobs\DispatchWebhookJob;
use App\Models\Webhook;
use App\Models\Order;
use App\Models\CheckoutSession;
use App\Models\Subscription;
use App\Support\WebhookPayloadBuilder;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WebhookEventSubscriber
{
    /**
     * @return array<string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        $eventClasses = array_keys(config('webhook_events.events', []));
        $map = [];
        foreach ($eventClasses as $class) {
            if (class_exists($class)) {
                $map[$class] = 'handleEvent';
            }
        }

        return $map;
    }

    public function handleEvent(object $event): void
    {
        $eventClass = $event::class;

        try {
            Log::debug('WebhookEventSubscriber: received event', [
                'event_class' => $eventClass,
            ]);

            $tenantIds = $this->getTenantIdsFromEvent($event);

            if (empty($tenantIds)) {
                Log::debug('WebhookEventSubscriber: no tenant ids resolved', [
                    'event_class' => $eventClass,
                ]);
                return;
            }

            $productId = $this->getProductIdFromEvent($event);

            $webhooks = Webhook::active()
                ->where(function ($q) use ($tenantIds) {
                    $q->whereIn('tenant_id', $tenantIds)
                        ->orWhereNull('tenant_id');
                })
                ->with('products')
                ->get();

            Log::debug('WebhookEventSubscriber: candidate webhooks loaded', [
                'event_class' => $eventClass,
                'tenant_ids' => $tenantIds,
                'product_id' => $productId,
                'count' => $webhooks->count(),
            ]);

            $payload = $this->buildPayload($event);
            $dispatchSync = $this->shouldDispatchSync($eventClass);

            foreach ($webhooks as $webhook) {
                if (! $webhook->listensTo($eventClass) || ! $webhook->shouldFireForProduct($productId)) {
                    continue;
                }

                try {
                    if ($eventClass === AccessDeliveryReady::class) {
                        DispatchWebhookJob::dispatchSync($webhook->id, $eventClass, $payload);
                    } elseif ($dispatchSync) {
                        DispatchWebhookJob::dispatchAfterResponse($webhook->id, $eventClass, $payload);
                    } else {
                        DispatchWebhookJob::dispatch($webhook->id, $eventClass, $payload);
                    }
                } catch (\Throwable $e) {
                    Log::warning('WebhookEventSubscriber: failed to dispatch webhook', [
                        'webhook_id' => $webhook->id,
                        'event_class' => $eventClass,
                        'tenant_id' => $webhook->tenant_id,
                        'message' => $e->getMessage(),
                    ]);

                    report($e);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('WebhookEventSubscriber: failed to handle event', [
                'event_class' => $eventClass,
                'message' => $e->getMessage(),
            ]);

            report($e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(object $event): array
    {
        if ($event instanceof OrderPending || $event instanceof OrderCompleted
            || $event instanceof OrderRejected || $event instanceof OrderCancelled
            || $event instanceof OrderRefunded || $event instanceof PixGenerated
            || $event instanceof BoletoGenerated) {
            $extras = [];
            if ($event instanceof PixGenerated && ! empty($event->pixData)) {
                $extras['pix'] = [
                    'qrcode' => $event->pixData['qrcode'] ?? null,
                    'copy_paste' => $event->pixData['copy_paste'] ?? null,
                    'transaction_id' => $event->pixData['transaction_id'] ?? null,
                ];
            }
            if ($event instanceof BoletoGenerated && ! empty($event->boletoData)) {
                $extras['boleto'] = [
                    'amount' => $event->boletoData['amount'] ?? null,
                    'expire_at' => $event->boletoData['expire_at'] ?? null,
                    'barcode' => $event->boletoData['barcode'] ?? null,
                    'pdf_url' => $event->boletoData['pdf_url'] ?? null,
                ];
            }

            return WebhookPayloadBuilder::forOrderEvent($event->order, $extras);
        }

        if ($event instanceof AccessDeliveryReady) {
            $user = $event->user;
            $product = $event->product;
            if ($event->order !== null) {
                $user = $user ?? $event->order->user;
                $product = $product ?? $event->order->product;
            }

            if ($user instanceof \App\Models\User && $product instanceof \App\Models\Product) {
                return WebhookPayloadBuilder::forAccessDeliveryReady(
                    $user,
                    $product,
                    is_array($event->access) ? $event->access : [],
                    is_array($event->context) ? $event->context : [],
                    $event->order,
                );
            }

            if ($event->order !== null) {
                return WebhookPayloadBuilder::forOrderEvent($event->order, WebhookPayloadBuilder::sanitizeExtras([
                    'access' => is_array($event->access) ? $event->access : [],
                ]));
            }

            return [];
        }

        if ($event instanceof CartAbandoned) {
            return WebhookPayloadBuilder::forCartAbandoned($event->checkoutSession);
        }

        if ($event instanceof SubscriptionCreated || $event instanceof SubscriptionRenewed
            || $event instanceof SubscriptionCancelled || $event instanceof SubscriptionPastDue) {
            return WebhookPayloadBuilder::forSubscriptionEvent($event->subscription);
        }

        return $this->serializeEventPayload($event);
    }

    private function shouldDispatchSync(string $eventClass): bool
    {
        if (config('getfy.webhooks.dispatch_all_sync', false)) {
            return true;
        }

        if (config('getfy.webhooks.sync_critical_payment_events', true)
            && in_array($eventClass, [OrderCompleted::class, OrderPending::class], true)) {
            return true;
        }

        if (app()->environment('local')) {
            return true;
        }

        if (config('queue.default') === 'sync') {
            return true;
        }

        $heartbeat = Cache::get('queue_heartbeat');
        if (! is_string($heartbeat) || $heartbeat === '') {
            return true;
        }

        try {
            $last = \Illuminate\Support\Carbon::parse($heartbeat);
        } catch (\Throwable) {
            return true;
        }

        return $last->lt(now()->subMinutes(3));
    }

    /**
     * @return array<int|null>
     */
    private function getTenantIdsFromEvent(object $event): array
    {
        if ($event instanceof AccessDeliveryReady) {
            $ids = [];
            if ($event->product !== null && $event->product->tenant_id !== null) {
                $ids[] = (int) $event->product->tenant_id;
            }
            if ($event->user !== null && $event->user->tenant_id !== null) {
                $ids[] = (int) $event->user->tenant_id;
            }
            if ($event->order !== null) {
                $orderTenant = $event->order->tenant_id;
                if ($orderTenant === null && $event->order->relationLoaded('product') === false) {
                    $event->order->loadMissing('product:id,tenant_id');
                }
                $orderTenant = $orderTenant ?? $event->order->product?->tenant_id;
                if ($orderTenant !== null) {
                    $ids[] = (int) $orderTenant;
                }
            }

            return array_values(array_unique(array_filter($ids)));
        }

        $ids = [];
        foreach ((array) $event as $value) {
            if ($value instanceof Model) {
                $tid = $value->getAttribute('tenant_id');

                Log::debug('WebhookEventSubscriber: inspecting model for tenant_id', [
                    'model' => $value::class,
                    'id' => $value->getKey(),
                    'tenant_id_attr' => $tid,
                    'product_id_attr' => method_exists($value, 'getAttribute') ? $value->getAttribute('product_id') : null,
                ]);

                if ($tid === null) {
                    try {
                        if ($value instanceof Order) {
                            $value->loadMissing('product:id,tenant_id');
                            $tid = $value->product?->tenant_id;
                        } elseif ($value instanceof CheckoutSession) {
                            $value->loadMissing('product:id,tenant_id');
                            $tid = $value->product?->tenant_id;
                        } elseif ($value instanceof Subscription) {
                            $value->loadMissing('product:id,tenant_id');
                            $tid = $value->product?->tenant_id;
                        }
                    } catch (\Throwable $e) {
                        Log::debug('WebhookEventSubscriber: failed to infer tenant_id from related product', [
                            'model' => $value::class,
                            'id' => $value->getKey(),
                            'message' => $e->getMessage(),
                        ]);
                    }
                }

                if ($tid !== null) {
                    $ids[] = $tid;
                }
            }
            if ($value instanceof \Illuminate\Support\Collection) {
                foreach ($value as $item) {
                    if ($item instanceof Model) {
                        $tid = $item->getAttribute('tenant_id');
                        if ($tid !== null) {
                            $ids[] = $tid;
                        }
                    }
                }
            }
        }

        if (empty($ids) && auth()->check()) {
            $tid = auth()->user()->tenant_id;
            if ($tid !== null) {
                $ids[] = $tid;
            }
        }

        $ids = array_values(array_unique(array_filter($ids, fn ($v) => $v !== null)));

        return $ids;
    }

    private function getProductIdFromEvent(object $event): int|string|null
    {
        if ($event instanceof OrderPending || $event instanceof OrderCompleted
            || $event instanceof OrderRejected || $event instanceof OrderCancelled
            || $event instanceof OrderRefunded || $event instanceof PixGenerated
            || $event instanceof BoletoGenerated || $event instanceof AccessDeliveryReady) {
            if ($event instanceof AccessDeliveryReady) {
                return $event->product?->id ?? $event->order?->product_id;
            }

            return $event->order?->product_id;
        }

        if ($event instanceof CartAbandoned) {
            return $event->checkoutSession?->product_id;
        }

        if ($event instanceof SubscriptionCreated || $event instanceof SubscriptionRenewed
            || $event instanceof SubscriptionCancelled || $event instanceof SubscriptionPastDue) {
            return $event->subscription?->product_id;
        }

        return null;
    }

    /**
     * Fallback for unknown event shapes.
     *
     * @return array<string, mixed>
     */
    private function serializeEventPayload(object $event): array
    {
        $result = [];
        foreach ((array) $event as $key => $value) {
            $cleanKey = preg_replace('/^\x00[^\x00]*\x00/', '', $key);
            $result[$cleanKey] = $this->serializeValue($value);
        }

        return $result;
    }

    private function serializeValue(mixed $value): mixed
    {
        if ($value instanceof Order) {
            return WebhookPayloadBuilder::forOrderEvent($value);
        }

        if ($value instanceof Model) {
            return WebhookPayloadBuilder::sanitizePayload($value->toArray());
        }

        if ($value instanceof \ArrayObject) {
            return $this->serializeValue($value->getArrayCopy());
        }

        if (is_array($value)) {
            return array_map(fn ($v) => $this->serializeValue($v), $value);
        }

        return $value;
    }
}
