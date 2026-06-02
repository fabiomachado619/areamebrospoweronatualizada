<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Services\SubscriptionLifecycleService;
use App\Services\TeamAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AssinaturasController extends Controller
{
    public function index(Request $request, SubscriptionLifecycleService $lifecycle): Response
    {
        $tenantId = auth()->user()->tenant_id;
        $statusFilter = $request->query('status', 'all');
        if (! in_array($statusFilter, ['all', 'active', 'past_due', 'cancelled'], true)) {
            $statusFilter = 'all';
        }

        $baseQuery = Subscription::with(['user', 'product', 'subscriptionPlan'])
            ->forTenant($tenantId);

        if (auth()->user()?->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            $baseQuery->whereIn('product_id', $allowed ?: ['__none__']);
        }

        $stats = $this->buildStats(clone $baseQuery);

        $query = clone $baseQuery;
        if ($statusFilter !== 'all') {
            $query->where('status', $statusFilter);
        }

        $assinaturas = $query->orderByDesc('subscriptions.current_period_end')
            ->paginate(20)
            ->withQueryString()
            ->through(function ($s) use ($lifecycle) {
                $effectiveStatus = $lifecycle->effectiveStatus($s);

                return [
                    'id' => $s->id,
                    'user' => $s->user ? ['id' => $s->user->id, 'name' => $s->user->name, 'email' => $s->user->email] : null,
                    'product' => $s->product ? ['id' => $s->product->id, 'name' => $s->product->name] : null,
                    'plan' => $s->subscriptionPlan ? [
                        'id' => $s->subscriptionPlan->id,
                        'name' => $s->subscriptionPlan->name,
                        'interval' => $s->subscriptionPlan->interval,
                        'interval_label' => \App\Models\SubscriptionPlan::intervalLabels()[$s->subscriptionPlan->interval] ?? $s->subscriptionPlan->interval,
                    ] : null,
                    'current_period_start' => $s->current_period_start?->toDateString(),
                    'current_period_end' => $s->current_period_end?->toDateString(),
                    'access_until' => $lifecycle->accessUntil($s)?->toDateString(),
                    'renewable_until' => $lifecycle->renewableUntil($s)?->toDateString(),
                    'days_until_end' => $lifecycle->daysUntilPeriodEnd($s),
                    'status' => $s->status,
                    'effective_status' => $effectiveStatus,
                    'renewal_url' => url('/renovar/'.$s->renewal_token),
                ];
            });

        return Inertia::render('Assinaturas/Index', [
            'stats' => $stats,
            'statusFilter' => $statusFilter,
            'assinaturas' => $assinaturas,
        ]);
    }

    public function cancel(Request $request, Subscription $subscription, SubscriptionLifecycleService $lifecycle): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        if ($subscription->tenant_id !== $tenantId) {
            return response()->json(['success' => false, 'message' => 'Assinatura não encontrada.'], 404);
        }

        if (auth()->user()?->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            if (! in_array($subscription->product_id, $allowed, true)) {
                return response()->json(['success' => false, 'message' => 'Assinatura não encontrada.'], 404);
            }
        }

        if ($subscription->status === Subscription::STATUS_CANCELLED) {
            return response()->json(['success' => false, 'message' => 'Esta assinatura já está cancelada.'], 422);
        }

        $validated = $request->validate([
            'revoke_access_now' => ['sometimes', 'boolean'],
        ]);

        $revokeNow = (bool) ($validated['revoke_access_now'] ?? false);
        $lifecycle->cancelSubscription($subscription, revokeAccessNow: $revokeNow);

        return response()->json([
            'success' => true,
            'message' => $revokeNow
                ? 'Assinatura cancelada e acesso revogado.'
                : 'Assinatura cancelada. O acesso permanece até o fim da carência, se houver.',
        ]);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Subscription>  $baseQuery
     * @return array{ativas: int, past_due: int, canceladas: int, clientes: int, mrr: float}
     */
    private function buildStats($baseQuery): array
    {
        $ativas = (clone $baseQuery)->where('status', Subscription::STATUS_ACTIVE)->count();
        $pastDue = (clone $baseQuery)->where('status', Subscription::STATUS_PAST_DUE)->count();
        $canceladas = (clone $baseQuery)->where('status', Subscription::STATUS_CANCELLED)->count();
        $clientes = (clone $baseQuery)->where('status', Subscription::STATUS_ACTIVE)->distinct('user_id')->count('user_id');

        $mrrQuery = Subscription::query()
            ->where('subscriptions.tenant_id', auth()->user()->tenant_id)
            ->where('subscriptions.status', Subscription::STATUS_ACTIVE)
            ->join('subscription_plans', 'subscriptions.subscription_plan_id', '=', 'subscription_plans.id')
            ->where('subscription_plans.interval', '!=', 'lifetime');

        if (auth()->user()?->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            $mrrQuery->whereIn('subscriptions.product_id', $allowed ?: ['__none__']);
        }

        $mrr = round((float) $mrrQuery->sum('subscription_plans.price'), 2);

        return [
            'ativas' => $ativas,
            'past_due' => $pastDue,
            'canceladas' => $canceladas,
            'clientes' => $clientes,
            'mrr' => $mrr,
        ];
    }
}
