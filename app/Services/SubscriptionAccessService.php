<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;

class SubscriptionAccessService
{
    public function __construct(
        private readonly SubscriptionLifecycleService $lifecycle,
    ) {}

    public function userHasSubscriptionAccess(User $user, Product $product, ?Carbon $today = null): bool
    {
        if ($product->billing_type !== Product::BILLING_SUBSCRIPTION) {
            return $product->hasMemberAreaAccess($user);
        }

        $subscription = Subscription::query()
            ->where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->orderByDesc('current_period_end')
            ->first();

        if (! $subscription) {
            return $product->users()->where('user_id', $user->id)->exists();
        }

        return $this->lifecycle->hasAccess($subscription, $today);
    }

    public function revokeAccessForSubscription(Subscription $subscription): void
    {
        $user = $subscription->user;
        $product = $subscription->product;
        if (! $user || ! $product) {
            return;
        }

        $productIds = [$product->id];
        $comboIds = $subscription->subscriptionPlan?->combo_product_ids ?? [];
        if (is_array($comboIds)) {
            foreach ($comboIds as $id) {
                if ($id !== null && $id !== '') {
                    $productIds[] = (int) $id;
                }
            }
        }

        $productIds = array_values(array_unique(array_filter($productIds)));

        foreach ($productIds as $productId) {
            $user->products()->detach($productId);
        }
    }
}
