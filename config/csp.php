<?php

/**
 * Content-Security-Policy (checkout, painel e pixels).
 *
 * Domínios extra via .env (vírgulas) para self-hosted / integrações customizadas:
 *   CSP_EXTRA_SCRIPT_SRC, CSP_EXTRA_CONNECT_SRC, CSP_EXTRA_FRAME_SRC
 */

$scriptSources = [
    "'self'",
    "'unsafe-inline'",
    "'unsafe-eval'",
    // Pagamentos
    'https://js.stripe.com',
    'https://sdk.mercadopago.com',
    'https://http2.mlstatic.com',
    'https://*.mlstatic.com',
    'https://checkout.pagar.me',
    'https://cdn.cajupay.com.br',
    // Analytics / pixels
    'https://connect.facebook.net',
    'https://www.googletagmanager.com',
    'https://analytics.tiktok.com',
    'https://cdn.utmify.com.br',
    // Captcha
    'https://challenges.cloudflare.com',
    // Infra
    'https://static.cloudflareinsights.com',
];

$connectSources = [
    "'self'",
    // Stripe
    'https://api.stripe.com',
    // Mercado Pago
    'https://api.mercadopago.com',
    'https://*.mercadopago.com',
    'https://*.mercadopago.com.br',
    'https://http2.mlstatic.com',
    'https://*.mlstatic.com',
    'https://api.mercadolibre.com',
    'https://www.mercadolibre.com',
    'https://*.mercadolibre.com',
    // Pagar.me
    'https://api.pagar.me',
    // CajuPay
    'https://api.cajupay.com.br',
    'https://*.cajupay.com.br',
    // Efí — tokenização de cartão (payment-token-efi)
    'https://tokenizer.sejaefi.com.br',
    'https://cobrancas.api.efipay.com.br',
    'https://cobrancas-h.api.efipay.com.br',
    // Endereço
    'https://viacep.com.br',
    // Pixels / analytics
    'https://www.facebook.com',
    'https://www.googletagmanager.com',
    'https://analytics.tiktok.com',
    'https://www.google-analytics.com',
    'https://*.google-analytics.com',
    'https://analytics.google.com',
    'https://www.google.com',
    'https://region1.google-analytics.com',
    // Utmify
    'https://api.utmify.com.br',
    'https://cdn.utmify.com.br',
    // WebSocket / blobs (checkout SDKs)
    'wss:',
    'blob:',
];

$frameSources = [
    "'self'",
    'https://js.stripe.com',
    'https://hooks.stripe.com',
    'https://m.stripe.network',
    'https://www.mercadopago.com',
    'https://*.mercadopago.com',
    'https://*.mercadopago.com.br',
    'https://www.mercadolibre.com',
    'https://*.mercadolibre.com',
    'https://www.youtube-nocookie.com',
    'https://youtube-nocookie.com',
    'https://www.youtube.com',
    'https://youtube.com',
    'https://challenges.cloudflare.com',
    'https://*.cajupay.com.br',
    'https://checkout.pagar.me',
];

return [
    /*
    | Origens HTTPS extra (separadas por vírgula no .env).
    */
    'extra_script_src' => env('CSP_EXTRA_SCRIPT_SRC', ''),
    'extra_connect_src' => env('CSP_EXTRA_CONNECT_SRC', ''),
    'extra_frame_src' => env('CSP_EXTRA_FRAME_SRC', ''),

    /*
    | Incluir https://r2.getfy.cloud em connect-src (storage público Getfy Cloud).
    */
    'disable_getfy_r2_origin' => filter_var(env('CSP_DISABLE_GETFY_R2_ORIGIN', false), FILTER_VALIDATE_BOOL),

    'script_src' => $scriptSources,
    /** script-src-elem: browsers modernos aplicam esta diretiva a <script src>. */
    'script_src_elem' => $scriptSources,
    'style_src' => ["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com'],
    'img_src' => ["'self'", 'data:', 'https:', 'blob:'],
    'font_src' => ["'self'", 'https://fonts.gstatic.com'],
    'connect_src' => $connectSources,
    'frame_src' => $frameSources,
    'media_src' => ["'self'", 'https:', 'blob:'],
    'worker_src' => ["'self'", 'blob:'],
];
