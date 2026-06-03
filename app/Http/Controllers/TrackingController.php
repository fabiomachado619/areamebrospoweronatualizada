<?php

namespace App\Http\Controllers;

use App\Models\TrackingAdSpend;
use App\Models\TrackingAdSpendOverride;
use App\Services\TrackingService;
use App\Support\ReportingPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrackingController extends Controller
{
    public function __construct(
        private readonly TrackingService $trackingService
    ) {}

    public function data(Request $request): JsonResponse
    {
        $period = $request->query('period', 'hoje');
        if (! in_array($period, TrackingService::PERIODS, true)) {
            $period = 'hoje';
        }

        $user = auth()->user();
        $payload = $this->trackingService->buildPayload($user->tenant_id, $period, $user);

        return response()->json($payload);
    }

    public function updateDailyAdSpend(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0'],
        ]);

        $tenantId = auth()->user()->tenant_id;

        TrackingAdSpend::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'spent_on' => $validated['date'],
                'currency' => 'BRL',
            ],
            ['amount' => round((float) $validated['amount'], 2)]
        );

        $this->trackingService->bustCache($tenantId);

        return response()->json(['success' => true]);
    }

    public function updatePeriodAdSpend(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period_key' => ['required', 'string', 'in:'.implode(',', TrackingService::PERIODS)],
            'amount' => ['required', 'numeric', 'min:0'],
        ]);

        $tenantId = auth()->user()->tenant_id;
        [$start, $end] = ReportingPeriod::boundsForDashboard($validated['period_key']);

        TrackingAdSpendOverride::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'period_key' => $validated['period_key'],
                'currency' => 'BRL',
            ],
            [
                'amount' => round((float) $validated['amount'], 2),
                'period_start' => $start?->toDateString(),
                'period_end' => $end?->toDateString(),
            ]
        );

        $this->trackingService->bustCache($tenantId);

        return response()->json(['success' => true]);
    }

    public function deletePeriodAdSpend(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period_key' => ['required', 'string', 'in:'.implode(',', TrackingService::PERIODS)],
        ]);

        $tenantId = auth()->user()->tenant_id;

        TrackingAdSpendOverride::forTenant($tenantId)
            ->where('period_key', $validated['period_key'])
            ->where('currency', 'BRL')
            ->delete();

        $this->trackingService->bustCache($tenantId);

        return response()->json(['success' => true]);
    }
}
