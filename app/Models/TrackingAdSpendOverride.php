<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrackingAdSpendOverride extends Model
{
    protected $fillable = [
        'tenant_id',
        'period_key',
        'period_start',
        'period_end',
        'amount',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $tenantId === null
            ? $query->whereNull('tenant_id')
            : $query->where('tenant_id', $tenantId);
    }
}
