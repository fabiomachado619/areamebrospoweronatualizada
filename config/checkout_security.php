<?php

return [
    'enabled' => filter_var(env('CHECKOUT_ABUSE_GUARD_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    'honeypot_field' => env('CHECKOUT_HONEYPOT_FIELD', 'website'),

    'rate' => [
        'process_per_minute' => (int) env('CHECKOUT_RATE_PROCESS_PER_MINUTE', 10),
        'card_per_minute' => (int) env('CHECKOUT_RATE_CARD_PER_MINUTE', 5),
        'pix_per_5_minutes' => (int) env('CHECKOUT_RATE_PIX_PER_5MIN', 6),
        'email_per_hour' => (int) env('CHECKOUT_RATE_EMAIL_PER_HOUR', 15),
        'product_ip_per_hour' => (int) env('CHECKOUT_RATE_PRODUCT_IP_PER_HOUR', 15),
        'show_per_minute' => (int) env('CHECKOUT_RATE_SHOW_PER_MINUTE', 120),
    ],

    'pending' => [
        'max_per_ip_hour' => (int) env('CHECKOUT_MAX_PENDING_PER_IP_HOUR', 10),
        'max_per_email_hour' => (int) env('CHECKOUT_MAX_PENDING_PER_EMAIL_HOUR', 6),
        'lookback_hours' => (int) env('CHECKOUT_PENDING_LOOKBACK_HOURS', 1),
    ],

    'captcha' => [
        'mode' => env('CHECKOUT_CAPTCHA_MODE', 'adaptive'),
        'soft_attempts' => (int) env('CHECKOUT_CAPTCHA_SOFT_ATTEMPTS', 2),
        'soft_window_minutes' => (int) env('CHECKOUT_CAPTCHA_SOFT_WINDOW_MINUTES', 10),
        'site_key' => env('CHECKOUT_TURNSTILE_SITE_KEY', ''),
        'secret_key' => env('CHECKOUT_TURNSTILE_SECRET_KEY', ''),
    ],

    'stale_pending_hours' => (int) env('CHECKOUT_STALE_PENDING_HOURS', 24),
];
