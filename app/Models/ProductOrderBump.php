<?php

namespace App\Models;

use App\Models\Setting;
use App\Support\CheckoutCurrencyCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductOrderBump extends Model
{
    protected $fillable = [
        'product_id',
        'target_product_id',
        'target_product_offer_id',
        'target_subscription_plan_id',
        'title',
        'description',
        'price_override',
        'cta_title',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'price_override' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function targetProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'target_product_id');
    }

    public function targetProductOffer(): BelongsTo
    {
        return $this->belongsTo(ProductOffer::class, 'target_product_offer_id');
    }

    public function targetSubscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'target_subscription_plan_id');
    }

    /**
     * Preço original em BRL (produto ou oferta alvo, sem considerar price_override).
     */
    public function getOriginalAmountBrl(): float
    {
        if ($this->target_product_offer_id && $this->targetProductOffer) {
            $currency = $this->targetProductOffer->getCurrencyOrDefault();
            $price = (float) $this->targetProductOffer->price;

            return $this->convertToBrl($price, $currency);
        }
        if ($this->target_subscription_plan_id && $this->targetSubscriptionPlan) {
            $currency = $this->targetSubscriptionPlan->getCurrencyOrDefault();
            $price = (float) $this->targetSubscriptionPlan->price;

            return $this->convertToBrl($price, $currency);
        }
        $target = $this->targetProduct;
        if ($target && $target->billing_type === Product::BILLING_SUBSCRIPTION) {
            $plan = $target->subscriptionPlans()->orderBy('position')->orderBy('price')->first();
            if ($plan) {
                return $this->convertToBrl((float) $plan->price, $plan->getCurrencyOrDefault());
            }
        }
        if (! $target) {
            return 0.0;
        }
        $currency = $target->currency ?? 'BRL';
        $price = (float) $target->price;
        return $this->convertToBrl($price, $currency);
    }

    /**
     * Preço efetivo do bump em BRL (override ou preço do produto/oferta alvo).
     */
    public function getEffectiveAmountBrl(): float
    {
        if ($this->price_override !== null) {
            return (float) $this->price_override;
        }
        return $this->getOriginalAmountBrl();
    }

    private function convertToBrl(float $amount, string $currency): float
    {
        if ($currency === 'BRL') {
            return $amount;
        }
        $tenantId = $this->product?->tenant_id;
        $raw = Setting::get('currencies', null, $tenantId);
        $list = $raw
            ? (is_string($raw) ? json_decode($raw, true) : $raw)
            : config('products.currencies');

        return CheckoutCurrencyCatalog::brlFromForeignAmount(
            $amount,
            $currency,
            is_array($list) ? $list : []
        );
    }
}
