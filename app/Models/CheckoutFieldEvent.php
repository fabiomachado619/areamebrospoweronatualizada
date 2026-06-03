<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckoutFieldEvent extends Model
{
    public const EVENT_REACHED = 'reached';

    public const EVENT_COMPLETED = 'completed';

    public const UPDATED_AT = null;

    protected $fillable = [
        'checkout_session_id',
        'session_token',
        'tenant_id',
        'product_id',
        'field_key',
        'event',
    ];

    public function checkoutSession(): BelongsTo
    {
        return $this->belongsTo(CheckoutSession::class);
    }

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $tenantId === null
            ? $query->whereNull('tenant_id')
            : $query->where('tenant_id', $tenantId);
    }
}
