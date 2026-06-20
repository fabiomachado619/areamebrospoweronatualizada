<?php

namespace Tests\Unit;

use App\Services\WebhookPayloadNormalizer;
use Tests\TestCase;

class WebhookPayloadNormalizerTest extends TestCase
{
    private WebhookPayloadNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new WebhookPayloadNormalizer;
    }

    public function test_notascast_payload_is_normalized_and_granted(): void
    {
        $payload = [
            'body' => [
                'name' => 'fabio machado',
                'whatsapp' => '+5565992976877',
                'email' => 'ciepoweron@gmail.com',
            ],
        ];

        $normalized = $this->normalizer->normalize($payload);

        $this->assertSame('fabio machado', $normalized['name']);
        $this->assertSame('ciepoweron@gmail.com', $normalized['email']);
        $this->assertSame('+5565992976877', $normalized['phone']);
        $this->assertSame('notascast', $normalized['platform']);
        $this->assertSame('lead_created', $normalized['event']);
        $this->assertTrue($this->normalizer->isApprovedForGrant($normalized));
    }

    public function test_poweron_payload_is_normalized(): void
    {
        $payload = [
            'body' => [
                'event' => 'pedido_pago',
                'payload' => [
                    'order' => ['id' => 90001, 'status' => 'completed'],
                    'customer' => [
                        'name' => 'Cliente Exemplo',
                        'email' => 'exemplo@email.com',
                        'phone' => '5511999999999',
                        'docNumber' => '12345678900',
                    ],
                    'status' => 'paid',
                    'payment' => ['gateway_transaction_id' => 'tx_exemplo_123'],
                    'product' => ['id' => 'prod-exemplo-uuid', 'name' => 'MeuLink - Full Anual'],
                ],
            ],
        ];

        $normalized = $this->normalizer->normalize($payload);

        $this->assertSame('Cliente Exemplo', $normalized['name']);
        $this->assertSame('exemplo@email.com', $normalized['email']);
        $this->assertSame('5511999999999', $normalized['phone']);
        $this->assertSame('12345678900', $normalized['document']);
        $this->assertSame('poweron', $normalized['platform']);
        $this->assertSame('pedido_pago', $normalized['event']);
        $this->assertSame('paid', $normalized['status']);
        $this->assertSame('tx_exemplo_123', $normalized['transaction_id']);
        $this->assertSame('prod-exemplo-uuid', $normalized['product_id']);
        $this->assertTrue($this->normalizer->isApprovedForGrant($normalized));
    }

    public function test_kiwify_payload_is_normalized(): void
    {
        $payload = [
            'body' => [
                'order_id' => '74821822-d31c-47ca-adf0-77ba9f27fc52',
                'order_status' => 'paid',
                'webhook_event_type' => 'order_approved',
                'Product' => [
                    'product_id' => 'c83d92c4-2b4a-4e65-8709-53c07a3f636e',
                    'product_name' => 'Example product',
                ],
                'Customer' => [
                    'full_name' => 'John Doe',
                    'email' => 'johndoe@example.com',
                    'mobile' => '+48196290118',
                    'CPF' => '91969350230',
                ],
            ],
        ];

        $normalized = $this->normalizer->normalize($payload);

        $this->assertSame('John Doe', $normalized['name']);
        $this->assertSame('johndoe@example.com', $normalized['email']);
        $this->assertSame('kiwify', $normalized['platform']);
        $this->assertSame('order_approved', $normalized['event']);
        $this->assertSame('paid', $normalized['status']);
        $this->assertTrue($this->normalizer->isApprovedForGrant($normalized));
    }

    public function test_wiapy_payload_at_root_is_normalized(): void
    {
        $payload = [
            'data' => [
                'payment' => [
                    'id' => '6a0f8466a3c7521472cb78ab',
                    'status' => 'paid',
                ],
                'customer' => [
                    'name' => 'Thiago Rodrigues',
                    'email' => 'thiagorodrigues386@gmail.com',
                    'mobile_phone' => '(19) 99181-1735',
                    'document' => '340.884.208-61',
                ],
                'checkout' => [
                    'id' => '69e2454d77c2484f353b5e23',
                    'title' => 'Scripts UPA Atualizados 2025.2',
                ],
                'products' => [
                    ['id' => '69e243c177c2484f353adbaa', 'title' => 'Scripts UPA Atualizados 2025.2 -POWER ON'],
                ],
            ],
        ];

        $normalized = $this->normalizer->normalize($payload);

        $this->assertSame('Thiago Rodrigues', $normalized['name']);
        $this->assertSame('thiagorodrigues386@gmail.com', $normalized['email']);
        $this->assertSame('wiapy', $normalized['platform']);
        $this->assertSame('paid', $normalized['status']);
        $this->assertTrue($this->normalizer->isApprovedForGrant($normalized));
    }

    public function test_hotmart_payload_is_normalized(): void
    {
        $payload = [
            'body' => [
                'data' => [
                    'product' => [
                        'id' => 0,
                        'ucode' => 'fb056612-bcc6-4217-9e6d-2a5d1110ac2f',
                        'name' => 'Produto test postback2',
                    ],
                    'purchase' => [
                        'transaction' => 'HP16015479281022',
                        'status' => 'COMPLETED',
                    ],
                    'buyer' => [
                        'name' => 'Teste Comprador',
                        'email' => 'testeComprador271101postman15@example.com',
                        'checkout_phone' => '99999999900',
                        'document' => '69526128664',
                    ],
                ],
                'event' => 'PURCHASE_COMPLETE',
            ],
        ];

        $normalized = $this->normalizer->normalize($payload);

        $this->assertSame('Teste Comprador', $normalized['name']);
        $this->assertSame('testecomprador271101postman15@example.com', $normalized['email']);
        $this->assertSame('hotmart', $normalized['platform']);
        $this->assertSame('purchase_complete', $normalized['event']);
        $this->assertSame('completed', $normalized['status']);
        $this->assertTrue($this->normalizer->isApprovedForGrant($normalized));
    }

    public function test_canonical_payload_passthrough(): void
    {
        $payload = [
            'name' => 'Nome do Aluno',
            'email' => 'aluno@example.com',
            'phone' => '559999999999',
            'document' => '00000000000',
            'platform' => 'kiwify',
            'event' => 'purchase_approved',
            'transaction_id' => 'abc123',
            'status' => 'approved',
            'send_access_email' => true,
        ];

        $normalized = $this->normalizer->normalize($payload);
        $request = $this->normalizer->toEnrollmentRequest($normalized);

        $this->assertSame('Nome do Aluno', $request['name']);
        $this->assertSame('aluno@example.com', $request['email']);
        $this->assertSame('purchase_approved', $request['event']);
        $this->assertTrue($request['send_access_email']);
    }

    public function test_missing_email_is_not_approved_and_name_falls_back_to_email(): void
    {
        $payload = [
            'body' => [
                'name' => 'Sem Email',
                'whatsapp' => '+5565999999999',
            ],
        ];

        $normalized = $this->normalizer->normalize($payload);

        $this->assertNull($normalized['email']);
        $this->assertFalse($this->normalizer->isApprovedForGrant($normalized));
    }

    public function test_pending_payment_is_ignored(): void
    {
        $payload = [
            'body' => [
                'order_id' => 'order-pending',
                'order_status' => 'waiting_payment',
                'webhook_event_type' => 'order_created',
                'Customer' => [
                    'full_name' => 'Pending User',
                    'email' => 'pending@example.com',
                ],
            ],
        ];

        $normalized = $this->normalizer->normalize($payload);

        $this->assertTrue($this->normalizer->isIgnored($normalized));
    }

    public function test_to_enrollment_request_maps_product_id_to_external_product_id(): void
    {
        $normalized = $this->normalizer->normalize([
            'body' => [
                'event' => 'pedido_pago',
                'payload' => [
                    'customer' => ['name' => 'A', 'email' => 'a@test.com'],
                    'status' => 'paid',
                    'product' => ['id' => 'external-prod-1'],
                ],
            ],
        ]);

        $request = $this->normalizer->toEnrollmentRequest($normalized);

        $this->assertSame('external-prod-1', $request['external_product_id']);
        $this->assertSame('purchase_approved', $request['event']);
    }
}
