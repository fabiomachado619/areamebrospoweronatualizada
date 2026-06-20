<?php

namespace Plugins\AutoZap\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugins\AutoZap\Models\AutoZapFlow;

require_once __DIR__ . '/../Models/AutoZapFlow.php';

class AutoZapFlowsController
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()?->tenant_id;
        $productId = $request->query('product_id');
        $triggerEvent = $request->query('trigger_event');

        $q = AutoZapFlow::query()->where('tenant_id', $tenantId)->orderBy('id', 'desc');
        if (is_string($productId) && $productId !== '') {
            $q->where('product_id', $productId);
        }
        if (is_string($triggerEvent) && $triggerEvent !== '') {
            $q->where('trigger_event', $triggerEvent);
        }

        return response()->json([
            'flows' => $q->get()->map(fn (AutoZapFlow $f) => [
                'id' => $f->id,
                'name' => $f->name,
                'product_id' => $f->product_id,
                'trigger_event' => $f->trigger_event,
                'is_active' => (bool) $f->is_active,
                'graph_json' => $f->graph_json,
            ])->values()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()?->tenant_id;
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'product_id' => ['nullable', 'string', 'max:64'],
            'trigger_event' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'graph_json' => ['required', 'array'],
        ]);

        $this->validateGraph($validated['graph_json']);

        $flow = AutoZapFlow::create([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'product_id' => $validated['product_id'] ?? null,
            'trigger_event' => $validated['trigger_event'],
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'graph_json' => $validated['graph_json'],
        ]);

        return response()->json(['ok' => true, 'id' => $flow->id], 201);
    }

    public function update(Request $request, int $flow): JsonResponse
    {
        $tenantId = $request->user()?->tenant_id;
        $flowModel = AutoZapFlow::findOrFail($flow);
        if ((int) $flowModel->tenant_id !== (int) $tenantId) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'graph_json' => ['nullable', 'array'],
        ]);

        if (array_key_exists('graph_json', $validated) && is_array($validated['graph_json'])) {
            $this->validateGraph($validated['graph_json']);
        }

        $flowModel->update([
            ...array_filter([
                'name' => $validated['name'] ?? null,
                'is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : null,
                'graph_json' => $validated['graph_json'] ?? null,
            ], fn ($v) => $v !== null),
        ]);

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, int $flow): JsonResponse
    {
        $tenantId = $request->user()?->tenant_id;
        $flowModel = AutoZapFlow::findOrFail($flow);
        if ((int) $flowModel->tenant_id !== (int) $tenantId) {
            abort(403);
        }
        $flowModel->delete();
        return response()->json(['ok' => true]);
    }

    /**
     * @param  array<string, mixed>  $graph
     */
    private function validateGraph(array $graph): void
    {
        $nodes = $graph['nodes'] ?? null;
        $edges = $graph['edges'] ?? null;
        if (! is_array($nodes) || ! is_array($edges)) {
            abort(422, 'Grafo inválido: nodes/edges obrigatórios.');
        }
        if (count($nodes) > 200 || count($edges) > 400) {
            abort(422, 'Grafo muito grande.');
        }
        $hasTrigger = false;
        foreach ($nodes as $n) {
            if (! is_array($n)) abort(422, 'Node inválido.');
            $id = (string) ($n['id'] ?? '');
            $type = (string) ($n['type'] ?? '');
            if ($id === '' || $type === '') abort(422, 'Node inválido: id/type.');
            if ($type === 'trigger') $hasTrigger = true;
        }
        if (! $hasTrigger) {
            abort(422, 'Grafo inválido: precisa de um node trigger.');
        }
        foreach ($edges as $e) {
            if (! is_array($e)) abort(422, 'Edge inválida.');
            $from = (string) ($e['from'] ?? '');
            $to = (string) ($e['to'] ?? '');
            if ($from === '' || $to === '') abort(422, 'Edge inválida: from/to.');
        }
    }
}

