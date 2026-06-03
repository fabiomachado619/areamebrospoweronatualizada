<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrackingAdSpend extends Model
{
    protected $fillable = [
        'tenant_id',
        'spent_on',
        'amount',
        'currency',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'spent_on' => 'date',
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
