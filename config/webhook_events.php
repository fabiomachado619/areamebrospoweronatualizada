<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Webhook Events Catalog
    | Event class => Human-readable label (for UI)
    |--------------------------------------------------------------------------
    */
    'events' => [
        // Pagamento
        \App\Events\OrderPending::class => 'Pedido pendente',
        \App\Events\OrderCompleted::class => 'Pedido pago',
        \App\Events\AccessDeliveryReady::class => 'Envio de acesso (pós-aprovação)',
        \App\Events\MemberAccessGranted::class => 'Liberação de acesso (matrícula/aluno)',
        \App\Events\OrderRejected::class => 'Pagamento recusado',
        \App\Events\OrderCancelled::class => 'Pedido cancelado',
        \App\Events\OrderRefunded::class => 'Reembolso',
        \App\Events\PixGenerated::class => 'Pix gerado',
        \App\Events\BoletoGenerated::class => 'Boleto gerado',
        \App\Events\CartAbandoned::class => 'Carrinho abandonado',

        // Assinatura
        \App\Events\SubscriptionCreated::class => 'Assinatura criada',
        \App\Events\SubscriptionRenewed::class => 'Assinatura renovada',
        \App\Events\SubscriptionCancelled::class => 'Assinatura cancelada',
        \App\Events\SubscriptionPastDue::class => 'Assinatura em atraso',
    ],

    /*
    |--------------------------------------------------------------------------
    | Event slugs (enviados no payload: event = slug, ex: pedido_pago)
    |--------------------------------------------------------------------------
    */
    'event_slugs' => [
        \App\Events\OrderPending::class => 'pedido_pendente',
        \App\Events\OrderCompleted::class => 'pedido_pago',
        \App\Events\AccessDeliveryReady::class => 'envio_acesso',
        \App\Events\MemberAccessGranted::class => 'liberacao_acesso',
        \App\Events\OrderRejected::class => 'pagamento_recusado',
        \App\Events\OrderCancelled::class => 'pedido_cancelado',
        \App\Events\OrderRefunded::class => 'reembolso',
        \App\Events\PixGenerated::class => 'pix_gerado',
        \App\Events\BoletoGenerated::class => 'boleto_gerado',
        \App\Events\CartAbandoned::class => 'carrinho_abandonado',
        \App\Events\SubscriptionCreated::class => 'assinatura_criada',
        \App\Events\SubscriptionRenewed::class => 'assinatura_renovada',
        \App\Events\SubscriptionCancelled::class => 'assinatura_cancelada',
        \App\Events\SubscriptionPastDue::class => 'assinatura_em_atraso',
    ],

    /*
    |--------------------------------------------------------------------------
    | UI: grupos e descrições (modal de payload / sidebar)
    |--------------------------------------------------------------------------
    */
    'groups' => [
        'payment' => [
            'label' => 'Pagamento',
            'slugs' => [
                'pedido_pendente',
                'pedido_pago',
                'envio_acesso',
                'liberacao_acesso',
                'pagamento_recusado',
                'pedido_cancelado',
                'reembolso',
                'pix_gerado',
                'boleto_gerado',
            ],
        ],
        'recovery' => [
            'label' => 'Recuperação',
            'slugs' => ['carrinho_abandonado'],
        ],
        'subscription' => [
            'label' => 'Assinatura',
            'slugs' => [
                'assinatura_criada',
                'assinatura_renovada',
                'assinatura_cancelada',
                'assinatura_em_atraso',
            ],
        ],
    ],

    'descriptions' => [
        'pedido_pendente' => 'Pedido criado aguardando confirmação do pagamento.',
        'pedido_pago' => 'Pagamento confirmado — compra aprovada.',
        'envio_acesso' => 'Acesso liberado após aprovação (área de membros, link, etc.).',
        'liberacao_acesso' => 'Acesso liberado por matrícula, cadastro manual ou liberação de curso.',
        'pagamento_recusado' => 'Pagamento recusado pelo gateway ou antifraude.',
        'pedido_cancelado' => 'Pedido cancelado antes da conclusão.',
        'reembolso' => 'Valor reembolsado ao comprador.',
        'pix_gerado' => 'Código Pix gerado no checkout (copia e cola / QR).',
        'boleto_gerado' => 'Boleto emitido com vencimento e linha digitável.',
        'carrinho_abandonado' => 'Visitante iniciou o checkout mas não concluiu a compra.',
        'assinatura_criada' => 'Nova assinatura ativa no produto recorrente.',
        'assinatura_renovada' => 'Cobrança de renovação confirmada.',
        'assinatura_cancelada' => 'Assinatura cancelada pelo cliente ou painel.',
        'assinatura_em_atraso' => 'Pagamento da renovação em atraso.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Test event (manual trigger from UI)
    |--------------------------------------------------------------------------
    */
    'test_event' => 'webhook.test',
    'test_event_label' => 'Evento de teste (manual)',
];
