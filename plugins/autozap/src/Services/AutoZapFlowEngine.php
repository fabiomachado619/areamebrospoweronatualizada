<?php

namespace Plugins\AutoZap\Services;

use Plugins\AutoZap\Jobs\AutoZapRunFlowJob;
use Plugins\AutoZap\Models\AutoZapConnection;
use Plugins\AutoZap\Models\AutoZapFlow;
use Plugins\AutoZap\Models\AutoZapFlowRun;
use Plugins\AutoZap\Providers\EvolutionApiProvider;
use Plugins\AutoZap\Providers\MenuiaProvider;
use Plugins\AutoZap\Providers\ZApiProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

require_once __DIR__ . '/../Jobs/AutoZapRunFlowJob.php';
require_once __DIR__ . '/../Models/AutoZapConnection.php';
require_once __DIR__ . '/../Models/AutoZapFlow.php';
require_once __DIR__ . '/../Models/AutoZapFlowRun.php';
require_once __DIR__ . '/../Providers/ZApiProvider.php';
require_once __DIR__ . '/../Providers/EvolutionApiProvider.php';
require_once __DIR__ . '/../Providers/MenuiaProvider.php';
require_once __DIR__ . '/AutoZapPayload.php';
require_once __DIR__ . '/AutoZapTemplate.php';

class AutoZapFlowEngine
{
    /**
     * Execute a flow graph starting from Trigger (or an explicit node).
     *
     * Graph format (MVP):
     * - graph_json.nodes: [{ id, type, data }]
     * - graph_json.edges: [{ from, to, data?: { condition?: 'true'|'false' } }]
     *
     * @param  array{tenant_id:?int, run_id:int, entity_refs:array<string,mixed>, start_node_id?:?string}  $context
     */
    public function run(AutoZapFlow $flow, object $event, array $context): void
    {
        $tenantId = $context['tenant_id'] ?? null;
        $runId = (int) ($context['run_id'] ?? 0);
        $startNodeId = $context['start_node_id'] ?? null;

        $graph = is_array($flow->graph_json) ? $flow->graph_json : [];
        $nodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];
        $edges = is_array($graph['edges'] ?? null) ? $graph['edges'] : [];

        $nodeById = [];
        foreach ($nodes as $n) {
            if (is_array($n) && isset($n['id'])) {
                $nodeById[(string) $n['id']] = $n;
            }
        }

        $nextByFrom = [];
        foreach ($edges as $e) {
            if (! is_array($e)) continue;
            $from = (string) ($e['from'] ?? '');
            $to = (string) ($e['to'] ?? '');
            if ($from === '' || $to === '') continue;
            $nextByFrom[$from] = $nextByFrom[$from] ?? [];
            $nextByFrom[$from][] = $e;
        }

        $triggerId = null;
        if ($startNodeId !== null && $startNodeId !== '' && isset($nodeById[$startNodeId])) {
            $triggerId = $startNodeId;
        } else {
            foreach ($nodeById as $id => $n) {
                if (($n['type'] ?? null) === 'trigger') {
                    $triggerId = $id;
                    break;
                }
            }
        }
        if ($triggerId === null) {
            return;
        }

        $payload = AutoZapPayload::fromEvent($event);

        $conn = AutoZapConnection::forTenant($tenantId)->first();
        if (! $conn || ! $conn->is_active || ! $conn->hasCredentials($conn->provider)) {
            throw new \RuntimeException('AutoZap não está conectado.');
        }
        $cred = $conn->credentialsForProvider($conn->provider);
        $provider = match ($conn->provider) {
            'zapi' => new ZApiProvider($cred),
            'evolution' => new EvolutionApiProvider($cred),
            'menuia' => new MenuiaProvider($cred),
            default => throw new \RuntimeException('Provedor AutoZap inválido.'),
        };

        $currentId = $triggerId;
        $safety = 0;
        while ($currentId && $safety++ < 200) {
            $node = $nodeById[$currentId] ?? null;
            if (! is_array($node)) break;
            $type = (string) ($node['type'] ?? '');
            $data = is_array($node['data'] ?? null) ? $node['data'] : [];

            if ($type === 'trigger') {
                $currentId = $this->pickNext($nextByFrom, $currentId);
                continue;
            }
            if ($type === 'end') {
                AutoZapFlowRun::where('id', $runId)->update(['status' => 'completed', 'last_error' => null]);
                return;
            }
            if ($type === 'condition') {
                $result = $this->evalCondition($data, $payload, $event);
                $currentId = $this->pickNext($nextByFrom, $currentId, $result ? 'true' : 'false');
                continue;
            }
            if ($type === 'delay') {
                $seconds = (int) ($data['seconds'] ?? 0);
                $seconds = max(0, min(86400, $seconds));
                $next = $this->pickNext($nextByFrom, $currentId);
                if ($next === null) {
                    AutoZapFlowRun::where('id', $runId)->update(['status' => 'completed', 'last_error' => null]);
                    return;
                }
                AutoZapFlowRun::where('id', $runId)->update(['status' => 'delayed']);
                AutoZapRunFlowJob::dispatch($tenantId, (int) $flow->id, $event::class, $event, $context['entity_refs'] ?? [], $runId, $next)
                    ->delay(now()->addSeconds($seconds));
                return;
            }
            if ($type === 'send_message') {
                $rateKey = 'autozap:rate:' . ($tenantId ?? 'null') . ':' . $flow->id . ':' . now()->format('YmdHi');
                try {
                    $count = Cache::increment($rateKey);
                    if ($count === 1) {
                        Cache::put($rateKey, $count, now()->addMinutes(2));
                    }
                    if ($count > 60) {
                        Log::warning('AutoZap: rate limit reached', ['tenant_id' => $tenantId, 'flow_id' => $flow->id, 'count' => $count]);
                        throw new \RuntimeException('Limite de disparos do AutoZap atingido. Tente novamente em instantes.');
                    }
                } catch (\Throwable) {
                    // If cache is unavailable, do not block execution.
                }

                $to = AutoZapPayload::resolvePhone($payload);
                if ($to === '') {
                    throw new \RuntimeException('Cliente sem telefone para WhatsApp.');
                }
                $mode = (string) ($data['mode'] ?? 'text');
                $textTpl = (string) ($data['text'] ?? '');
                $text = AutoZapTemplate::render($textTpl, $payload);

                if ($mode === 'media') {
                    $mediaUrl = (string) ($data['media_url'] ?? '');
                    $mime = (string) ($data['mime_type'] ?? 'application/octet-stream');
                    if ($mediaUrl === '') {
                        $provider->sendText($to, $text, $payload);
                    } else {
                        $provider->sendMedia($to, $text, $mediaUrl, $mime, $payload);
                    }
                } elseif ($mode === 'interactive') {
                    $interactive = is_array($data['interactive'] ?? null) ? $data['interactive'] : ['text' => $text];
                    if (! isset($interactive['text'])) {
                        $interactive['text'] = $text;
                    }
                    $provider->sendInteractive($to, $interactive, $payload);
                } else {
                    $provider->sendText($to, $text, $payload);
                }

                Log::info('AutoZap: message sent', [
                    'tenant_id' => $tenantId,
                    'flow_id' => $flow->id,
                    'run_id' => $runId,
                    'to' => substr($to, 0, 6) . '***',
                    'mode' => $mode,
                    'event_class' => $event::class,
                ]);

                $currentId = $this->pickNext($nextByFrom, $currentId);
                continue;
            }

            // Unknown node: stop.
            break;
        }

        AutoZapFlowRun::where('id', $runId)->update(['status' => 'completed', 'last_error' => null]);
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $nextByFrom
     */
    private function pickNext(array $nextByFrom, string $fromId, ?string $condition = null): ?string
    {
        $edges = $nextByFrom[$fromId] ?? [];
        if ($edges === []) return null;
        if ($condition !== null) {
            foreach ($edges as $e) {
                $c = $e['data']['condition'] ?? null;
                if ($c === $condition) {
                    return (string) ($e['to'] ?? '');
                }
            }
        }
        return (string) ($edges[0]['to'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $payload
     */
    private function evalCondition(array $data, array $payload, object $event): bool
    {
        $kind = (string) ($data['kind'] ?? 'has_phone');
        if ($kind === 'has_phone') {
            return AutoZapPayload::resolvePhone($payload) !== '';
        }
        if ($kind === 'payment_method_is') {
            $expected = strtolower((string) ($data['value'] ?? ''));
            $method = strtolower((string) ($payload['order']['metadata']['checkout_payment_method'] ?? ''));
            return $expected !== '' && $method === $expected;
        }
        if ($kind === 'event_is') {
            $expected = (string) ($data['value'] ?? '');
            return $expected !== '' && $expected === $event::class;
        }
        return false;
    }
}

