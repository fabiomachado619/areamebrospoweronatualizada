<?php

namespace Plugins\AutoZap\Models;

use Illuminate\Database\Eloquent\Model;

class AutoZapFlowRun extends Model
{
    protected $table = 'autozap_flow_runs';

    protected $fillable = [
        'tenant_id',
        'flow_id',
        'event_class',
        'entity_refs',
        'status',
        'last_error',
    ];

    protected $casts = [
        'entity_refs' => 'array',
    ];
}

