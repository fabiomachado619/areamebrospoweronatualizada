<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Services\PanelPushService;
use App\Support\PanelOrderPushMessages;
use Illuminate\Support\Facades\Log;

class SendPanelPushOnOrderCompleted
{
    public function __construct(
        private readonly PanelPushService $panelPushService,
    ) {}

    public function handle(OrderCompleted $event): void
    {
        $order = $event->order->loadMissing('product');

        try {
            $message = PanelOrderPushMessages::forSaleApproved($order);

            $this->panelPushService->sendAndPersistToTenant(
                $order->tenant_id,
                'sale_approved',
                $message['title'],
                $message['body'],
                $message['url'],
                'sale_' . $order->id,
                $message['category']
            );
        } catch (\Throwable $e) {
            Log::warning('SendPanelPushOnOrderCompleted: falha ao enviar push', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
