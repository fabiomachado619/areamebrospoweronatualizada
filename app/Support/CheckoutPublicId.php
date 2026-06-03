<?php

namespace App\Support;

use App\Models\ProductOffer;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Str;

class CheckoutPublicId
{
    public static function generateUnique(): string
    {
        do {
            $id = Str::lower(Str::random(10));
        } while (static::exists($id));

        return $id;
    }

    public static function exists(string $publicId): bool
    {
        return ProductOffer::where('public_id', $publicId)->exists()
            || SubscriptionPlan::where('public_id', $publicId)->exists();
    }
}
