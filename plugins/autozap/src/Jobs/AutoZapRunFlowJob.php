<?php

namespace Plugins\AutoZap\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Plugins\AutoZap\Models\AutoZapFlow;
use Plugins\AutoZap\Models\AutoZapFlowRun;
use Plugins\AutoZap\Services\AutoZapFlowEngine;

require_once __DIR__ . '/../Models/AutoZapFlow.php';
require_once __DIR__ . '/../Models/AutoZapFlowRun.php';
require_once __DIR__ . '/../Services/AutoZapFlowEngine.php';

class AutoZapRunFlowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ?int $tenantId,
        public int $flowId,
        public string $eventClass,
        public object $event,
        /** @var array<string, mixed> */
        public array $entityRefs = [],
        public ?int $runId = null,
        public ?string $startNodeId = null
    ) {}

    public function handle(): void
    {
        $flow = AutoZapFlow::find($this->flowId);
        if (! $flow || ! $flow->is_active) {
            return;
        }

        $lockKey = 'autozap:flow:' . $this->flowId . ':' . md5(json_encode($this->entityRefs));
        if (! Cache::add($lockKey, true, now()->addMinutes(5))) {
            return;
        }

        $run = null;
        if ($this->runId) {
            $run = AutoZapFlowRun::find($this->runId);
        }
        if (! $run) {
            $run = AutoZapFlowRun::create([
                'tenant_id' => $this->tenantId,
                'flow_id' => $this->flowId,
                'event_class' => $this->eventClass,
                'entity_refs' => $this->entityRefs,
                'status' => 'running',
            ]);
        } else {
            $run->update(['status' => 'running']);
        }

        try {
            (new AutoZapFlowEngine)->run($flow, $this->event, [
                'tenant_id' => $this->tenantId,
                'run_id' => (int) $run->id,
                'entity_refs' => $this->entityRefs,
                'start_node_id' => $this->startNodeId,
            ]);
        } catch (\Throwable $e) {
            $run->update(['status' => 'failed', 'last_error' => $e->getMessage()]);
            throw $e;
        } finally {
            Cache::forget($lockKey);
        }
    }
}

