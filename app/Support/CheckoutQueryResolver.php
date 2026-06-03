<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;

class CheckoutQueryResolver
{
    public static function resolveOffer(Product $product, Request $request): ?ProductOffer
    {
        $offerToken = trim((string) $request->query('offer', ''));
        if ($offerToken !== '') {
            return ProductOffer::where('product_id', $product->id)
                ->where('public_id', $offerToken)
                ->first();
        }

        $legacyOfferId = $request->query('offer_id');
        if ($legacyOfferId !== null && $legacyOfferId !== '' && ctype_digit((string) $legacyOfferId)) {
            return ProductOffer::where('product_id', $product->id)
                ->where('id', (int) $legacyOfferId)
                ->first();
        }

        return null;
    }

    public static function resolvePlan(Product $product, Request $request): ?SubscriptionPlan
    {
        $planToken = trim((string) $request->query('plan', ''));
        if ($planToken !== '') {
            return SubscriptionPlan::where('product_id', $product->id)
                ->where('public_id', $planToken)
                ->first();
        }

        $legacyPlanId = $request->query('plan_id');
        if ($legacyPlanId !== null && $legacyPlanId !== '' && ctype_digit((string) $legacyPlanId)) {
            return SubscriptionPlan::where('product_id', $product->id)
                ->where('id', (int) $legacyPlanId)
                ->first();
        }

        return null;
    }
}
