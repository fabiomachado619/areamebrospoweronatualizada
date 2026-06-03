<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Carbon as IlluminateCarbon;
use Illuminate\Support\Facades\Cache;

class ReportingPeriod
{
    public static function timezone(): string
    {
        return (string) config('app.timezone', 'America/Sao_Paulo');
    }

    public static function now(): Carbon
    {
        return Carbon::now(self::timezone());
    }

    /**
     * Períodos do dashboard (hoje, ontem, …).
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    public static function boundsForDashboard(string $period): array
    {
        $now = self::now();
        $start = null;
        $end = null;

        switch ($period) {
            case 'hoje':
                $start = $now->copy()->startOfDay();
                $end = $now->copy()->endOfDay();
                break;
            case 'ontem':
                $start = $now->copy()->subDay()->startOfDay();
                $end = $now->copy()->subDay()->endOfDay();
                break;
            case '7dias':
                $start = $now->copy()->subDays(6)->startOfDay();
                $end = $now->copy()->endOfDay();
                break;
            case 'mes':
                $start = $now->copy()->startOfMonth()->startOfDay();
                $end = $now->copy()->endOfMonth()->endOfDay();
                break;
            case 'ano':
                $start = $now->copy()->startOfYear()->startOfDay();
                $end = $now->copy()->endOfYear()->endOfDay();
                break;
            case 'total':
                break;
        }

        return [$start, $end];
    }

    /**
     * Sufixo de data para cache do dashboard (evita servir "hoje" do dia anterior).
     */
    public static function dashboardCacheSuffix(string $period): string
    {
        $now = self::now();

        return match ($period) {
            'hoje' => $now->format('Y-m-d'),
            'ontem' => $now->copy()->subDay()->format('Y-m-d'),
            '7dias' => $now->format('Y-m-d'),
            'mes' => $now->format('Y-m'),
            'ano' => $now->format('Y'),
            default => 'all',
        };
    }

    /**
     * Períodos da tela de vendas (today, 7d, custom, …).
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    public static function boundsForVendas(?string $period, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $period = $period !== null && $period !== '' ? $period : 'all';

        if ($period === 'all') {
            return [null, null];
        }

        $now = self::now();
        $start = null;
        $end = null;

        if ($period === 'today') {
            $start = $now->copy()->startOfDay();
            $end = $now->copy()->endOfDay();
        } elseif ($period === '7d') {
            $start = $now->copy()->subDays(6)->startOfDay();
            $end = $now->copy()->endOfDay();
        } elseif ($period === '30d') {
            $start = $now->copy()->subDays(29)->startOfDay();
            $end = $now->copy()->endOfDay();
        } elseif ($period === 'this_month') {
            $start = $now->copy()->startOfMonth()->startOfDay();
            $end = $now->copy()->endOfDay();
        } elseif ($period === 'last_month') {
            $start = $now->copy()->subMonthNoOverflow()->startOfMonth()->startOfDay();
            $end = $now->copy()->subMonthNoOverflow()->endOfMonth()->endOfDay();
        } elseif ($period === 'custom') {
            $start = $dateFrom
                ? IlluminateCarbon::parse($dateFrom, self::timezone())->startOfDay()
                : null;
            $end = $dateTo
                ? IlluminateCarbon::parse($dateTo, self::timezone())->endOfDay()
                : null;
        }

        return [$start, $end];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     */
    public static function applyCreatedAtBounds($query, ?Carbon $start, ?Carbon $end): void
    {
        if ($start && $end) {
            $query->whereBetween('created_at', [$start, $end]);

            return;
        }
        if ($start) {
            $query->where('created_at', '>=', $start);
        }
        if ($end) {
            $query->where('created_at', '<=', $end);
        }
    }

    public static function bustDashboardCache(?int $tenantId): void
    {
        $key = 'dashboard_bust:'.($tenantId ?? 'global');
        Cache::put($key, (string) microtime(true), now()->addDays(2));
    }

    /** Invalidates dashboard + tracking aggregated caches (shared bust token). */
    public static function bustTrackingCache(?int $tenantId): void
    {
        self::bustDashboardCache($tenantId);
    }

    public static function dashboardBustToken(?int $tenantId): string
    {
        return (string) Cache::get('dashboard_bust:'.($tenantId ?? 'global'), '0');
    }
}
