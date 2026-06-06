<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnrollmentExternalProductMapping extends Model
{
    protected $fillable = [
        'tenant_id',
        'platform',
        'external_product_id',
        'product_id',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public static function resolveProductId(int $tenantId, string $platform, string $externalProductId): ?string
    {
        $mapping = static::query()
            ->where('tenant_id', $tenantId)
            ->where('platform', $platform)
            ->where('external_product_id', $externalProductId)
            ->first();

        return $mapping?->product_id;
    }
}
