<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | Webhook de matrícula (n8n / plataformas externas): qualquer origem
    | pode chamar o endpoint; autenticação pela URL única do webhook.
    |
    */

    'paths' => [
        'api/webhooks/enrollment',
        'api/webhooks/enrollment/*',
    ],

    'allowed_methods' => ['POST', 'OPTIONS'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Authorization',
        'Content-Type',
        'X-Signature',
        'Accept',
        'Origin',
        'X-Requested-With',
    ],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => false,

];
