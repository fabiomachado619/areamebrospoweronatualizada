<?php

namespace App\Listeners;

use App\Events\BoletoGenerated;
use App\Services\PanelPushService;
use App\Support\PanelOrderPushMessages;
use Illuminate\Support\Facades\Log;

class SendPanelPushOnBoletoGenerated
{
    public function __construct(
        private readonly PanelPushService $panelPushService,
    ) {}

    public function handle(BoletoGenerated $event): void
    {
        $order = $event->order->loadMissing('product');

        try {
            $message = PanelOrderPushMessages::forBoletoGenerated($order);

            $this->panelPushService->sendAndPersistToTenant(
                $order->tenant_id,
                'boleto_generated',
                $message['title'],
                $message['body'],
                $message['url'],
                'boleto_' . $order->id,
                $message['category']
            );
        } catch (\Throwable $e) {
            Log::warning('SendPanelPushOnBoletoGenerated: falha ao enviar push', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
