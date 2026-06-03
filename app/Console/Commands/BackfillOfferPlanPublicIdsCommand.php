<?php

namespace App\Console\Commands;

use App\Models\ProductOffer;
use App\Models\SubscriptionPlan;
use App\Support\CheckoutPublicId;
use Illuminate\Console\Command;

class BackfillOfferPlanPublicIdsCommand extends Command
{
    protected $signature = 'checkout:backfill-offer-plan-public-ids';

    protected $description = 'Gera public_id para ofertas e planos que ainda não possuem';

    public function handle(): int
    {
        $offersUpdated = $this->backfillOffers();
        $plansUpdated = $this->backfillPlans();

        $this->info("Ofertas atualizadas: {$offersUpdated}");
        $this->info("Planos atualizados: {$plansUpdated}");

        return self::SUCCESS;
    }

    private function backfillOffers(): int
    {
        $count = 0;
        ProductOffer::query()
            ->where(function ($q) {
                $q->whereNull('public_id')->orWhere('public_id', '');
            })
            ->orderBy('id')
            ->each(function (ProductOffer $offer) use (&$count) {
                $offer->update(['public_id' => CheckoutPublicId::generateUnique()]);
                $count++;
            });

        return $count;
    }

    private function backfillPlans(): int
    {
        $count = 0;
        SubscriptionPlan::query()
            ->where(function ($q) {
                $q->whereNull('public_id')->orWhere('public_id', '');
            })
            ->orderBy('id')
            ->each(function (SubscriptionPlan $plan) use (&$count) {
                $plan->update(['public_id' => CheckoutPublicId::generateUnique()]);
                $count++;
            });

        return $count;
    }
}
