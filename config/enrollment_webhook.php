<?php

return [
    'grant_events' => [
        'purchase_approved',
        'order_paid',
        'subscription_active',
        'approved',
    ],

    'revoke_events' => [
        'refund',
        'refunded',
        'chargeback',
        'canceled',
        'subscription_canceled',
        'subscription_expired',
    ],
];
