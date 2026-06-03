<?php

namespace App\Listeners;

use App\Events\PixGenerated;
use App\Services\PanelPushService;
use App\Support\PanelOrderPushMessages;
use Illuminate\Support\Facades\Log;

class SendPanelPushOnPixGenerated
{
    public function __construct(
        private readonly PanelPushService $panelPushService,
    ) {}

    public function handle(PixGenerated $event): void
    {
        $order = $event->order->loadMissing('product');

        try {
            $message = PanelOrderPushMessages::forPixGenerated($order);

            $this->panelPushService->sendAndPersistToTenant(
                $order->tenant_id,
                'pix_generated',
                $message['title'],
                $message['body'],
                $message['url'],
                'pix_' . $order->id,
                $message['category']
            );
        } catch (\Throwable $e) {
            Log::warning('SendPanelPushOnPixGenerated: falha ao enviar push', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
