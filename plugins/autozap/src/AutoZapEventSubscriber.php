<?php

namespace Plugins\AutoZap;

use Illuminate\Contracts\Events\Dispatcher;
use Plugins\AutoZap\Jobs\AutoZapRunFlowJob;
use Plugins\AutoZap\Models\AutoZapFlow;

require_once __DIR__ . '/Jobs/AutoZapRunFlowJob.php';
require_once __DIR__ . '/Models/AutoZapFlow.php';
require_once __DIR__ . '/AutoZapEventUtils.php';

class AutoZapEventSubscriber
{
    /**
     * @return array<string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        // MVP: subscribe only to events configured in config/webhook_events.php to reuse existing catalogue.
        // We'll filter flows by trigger_event anyway.
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

        // Resolve tenant_id + product_id best-effort by inspecting model payload.
        [$tenantId, $productId, $entityRefs] = AutoZapEventUtils::resolveContext($event);
        if ($tenantId === null) {
            return;
        }

        $flows = AutoZapFlow::query()
            ->where('tenant_id', $tenantId)
            ->where('trigger_event', $eventClass)
            ->where(function ($q) use ($productId) {
                if ($productId !== null && $productId !== '') {
                    $q->where('product_id', $productId)->orWhereNull('product_id');
                } else {
                    $q->whereNull('product_id');
                }
            })
            ->where('is_active', true)
            ->get();

        foreach ($flows as $flow) {
            AutoZapRunFlowJob::dispatch($tenantId, (int) $flow->id, $eventClass, $event, $entityRefs);
        }
    }
}

