<?php

namespace Plugins\AutoZap\Models;

use Illuminate\Database\Eloquent\Model;

class AutoZapFlow extends Model
{
    protected $table = 'autozap_flows';

    protected $fillable = [
        'tenant_id',
        'product_id',
        'trigger_event',
        'name',
        'is_active',
        'graph_json',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'graph_json' => 'array',
    ];
}

