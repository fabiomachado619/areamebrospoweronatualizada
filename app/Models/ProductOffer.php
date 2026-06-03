<?php

namespace App\Models;

use App\Support\CheckoutPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProductOffer extends Model
{
    protected $fillable = [
        'product_id',
        'public_id',
        'name',
        'price',
        'currency',
        'checkout_slug',
        'checkout_config',
        'position',
        'combo_product_ids',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'checkout_config' => 'array',
            'combo_product_ids' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ProductOffer $offer) {
            if (empty($offer->public_id)) {
                $offer->public_id = CheckoutPublicId::generateUnique();
            }
        });
    }

    /**
     * checkout_slug não é gerado por padrão: ofertas usam o checkout principal.
     * Só é definido quando o usuário cria um checkout exclusivo (ensureCheckoutSlug).
     */
    public static function generateUniqueCheckoutSlug(): string
    {
        do {
            $slug = Str::lower(Str::random(7));
        } while (static::slugExists($slug));

        return $slug;
    }

    public static function slugExists(string $slug): bool
    {
        return static::where('checkout_slug', $slug)->exists()
            || Product::where('checkout_slug', $slug)->exists()
            || SubscriptionPlan::where('checkout_slug', $slug)->exists();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getCurrencyOrDefault(): string
    {
        return $this->currency ?? $this->product?->currency ?? 'BRL';
    }

    public function ensurePublicId(): ?string
    {
        if (! Schema::hasColumn($this->getTable(), 'public_id')) {
            return $this->public_id;
        }

        if (! empty($this->public_id)) {
            return $this->public_id;
        }

        $this->public_id = CheckoutPublicId::generateUnique();
        $this->saveQuietly();

        return $this->public_id;
    }
}
