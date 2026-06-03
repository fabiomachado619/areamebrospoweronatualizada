<?php

namespace App\Console\Commands;

use App\Models\CheckoutSession;
use App\Models\Order;
use App\Services\GeoIp;
use Illuminate\Console\Command;

class BackfillTrackingCountriesCommand extends Command
{
    protected $signature = 'tracking:backfill-countries {--limit=500 : Max records per table per run}';

    protected $description = 'Preenche country_code em pedidos e sessões a partir de customer_ip';

    public function handle(GeoIp $geoIp): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $sessionsUpdated = $this->backfillSessions($geoIp, $limit);
        $ordersUpdated = $this->backfillOrders($geoIp, $limit);

        $this->info("Sessões atualizadas: {$sessionsUpdated}");
        $this->info("Pedidos atualizados: {$ordersUpdated}");

        return self::SUCCESS;
    }

    private function backfillSessions(GeoIp $geoIp, int $limit): int
    {
        $count = 0;
        CheckoutSession::query()
            ->whereNull('country_code')
            ->whereNotNull('customer_ip')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'customer_ip'])
            ->each(function (CheckoutSession $session) use ($geoIp, &$count) {
                $code = $geoIp->getSuggestionsForIp((string) $session->customer_ip)['country_code'] ?? null;
                if (is_string($code) && strlen($code) === 2) {
                    $session->update(['country_code' => strtoupper($code)]);
                    $count++;
                }
            });

        return $count;
    }

    private function backfillOrders(GeoIp $geoIp, int $limit): int
    {
        $count = 0;
        Order::query()
            ->whereNull('country_code')
            ->whereNotNull('customer_ip')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'customer_ip'])
            ->each(function (Order $order) use ($geoIp, &$count) {
                $code = $geoIp->getSuggestionsForIp((string) $order->customer_ip)['country_code'] ?? null;
                if (is_string($code) && strlen($code) === 2) {
                    $order->update(['country_code' => strtoupper($code)]);
                    $count++;
                }
            });

        return $count;
    }
}
