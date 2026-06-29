<?php

namespace Tests\Feature;

use App\Events\AccessDeliveryReady;
use App\Mail\AccessGrantedMail;
use App\Models\EnrollmentExternalProductMapping;
use App\Models\EnrollmentWebhookCredential;
use App\Models\Product;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EnrollmentWebhookGlobalFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutDefer();
    }

    private function createMemberCourse(array $overrides = []): Product
    {
        return $this->createTestProduct(array_merge([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'curso-'.substr(uniqid('', true), -8),
            'checkout_config' => [
                'email_template' => Product::defaultEmailTemplate(),
            ],
        ], $overrides));
    }

    /**
     * @return array<string, array{platform: string, external_product_id: string, build: callable(string): array<string, mixed>}>
     */
    private function platformCases(): array
    {
        return [
            'poweron' => [
                'platform' => 'poweron',
                'external_product_id' => 'prod-global-po',
                'build' => fn (string $email) => [
                    'body' => [
                        'event' => 'pedido_pendente',
                        'payload' => [
                            'order' => ['id' => random_int(96000, 96999), 'status' => 'pending'],
                            'customer' => [
                                'name' => 'Aluno Power On',
                                'email' => $email,
                                'phone' => '5511999887766',
                            ],
                            'status' => 'pending',
                            'payment' => ['gateway_transaction_id' => 'tx-po-'.uniqid()],
                            'product' => ['id' => 'prod-global-po', 'name' => 'Produto PO'],
                        ],
                    ],
                ],
            ],
            'kiwify' => [
                'platform' => 'kiwify',
                'external_product_id' => 'ext-global-kw',
                'build' => fn (string $email) => [
                    'body' => [
                        'order_id' => 'order-'.uniqid(),
                        'order_status' => 'waiting_payment',
                        'webhook_event_type' => 'order_created',
                        'Product' => ['product_id' => 'ext-global-kw', 'product_name' => 'Curso Kiwify'],
                        'Customer' => [
                            'full_name' => 'Aluno Kiwify',
                            'email' => $email,
                            'mobile' => '5511888777666',
                        ],
                    ],
                ],
            ],
            'hotmart' => [
                'platform' => 'hotmart',
                'external_product_id' => 'hotmart-global-ucode',
                'build' => fn (string $email) => [
                    'body' => [
                        'event' => 'PURCHASE_PROTEST',
                        'data' => [
                            'product' => [
                                'id' => 0,
                                'ucode' => 'hotmart-global-ucode',
                                'name' => 'Produto Hotmart',
                            ],
                            'purchase' => [
                                'transaction' => 'HP'.uniqid(),
                                'status' => 'WAITING_PAYMENT',
                            ],
                            'buyer' => [
                                'name' => 'Aluno Hotmart',
                                'email' => $email,
                                'checkout_phone' => '5511777666555',
                            ],
                        ],
                    ],
                ],
            ],
            'wiapy' => [
                'platform' => 'wiapy',
                'external_product_id' => 'prod-global-wy',
                'build' => fn (string $email) => [
                    'data' => [
                        'payment' => [
                            'id' => 'pay-'.uniqid(),
                            'status' => 'waiting_payment',
                        ],
                        'customer' => [
                            'name' => 'Aluno Wiapy',
                            'email' => $email,
                            'mobile_phone' => '(11) 98888-7777',
                        ],
                        'products' => [
                            ['id' => 'prod-global-wy', 'title' => 'Curso Wiapy'],
                        ],
                    ],
                ],
            ],
            'notascast' => [
                'platform' => 'notascast',
                'external_product_id' => 'notascast-no-product',
                'build' => fn (string $email) => [
                    'body' => [
                        'name' => 'Aluno Notascast',
                        'email' => $email,
                        'whatsapp' => '5511666555444',
                    ],
                ],
            ],
            'gg_checkout' => [
                'platform' => 'gg_checkout',
                'external_product_id' => 'YbfsgK1Fgm0LzUsFglrn',
                'build' => fn (string $email) => [
                    'event' => 'pix.generated',
                    'customer' => [
                        'name' => 'Aluno GG',
                        'email' => $email,
                        'phone' => '5511555444333',
                    ],
                    'payment' => [
                        'id' => 'pay-gg-'.uniqid(),
                        'status' => 'waiting_payment',
                    ],
                    'product' => [
                        'id' => 'YbfsgK1Fgm0LzUsFglrn',
                        'title' => 'Produto GG',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array{url: string, course: Product, mappedCourse: Product}
     */
    private function createWebhookWithMapping(string $platform, string $externalProductId): array
    {
        Mail::fake();

        $course = $this->createMemberCourse(['name' => 'Curso Webhook '.$platform]);
        $mappedCourse = $this->createMemberCourse(['name' => 'Curso Mapeado '.$platform]);

        EnrollmentExternalProductMapping::query()->create([
            'tenant_id' => 1,
            'platform' => $platform,
            'external_product_id' => $externalProductId,
            'product_id' => $mappedCourse->id,
        ]);

        ['model' => $credential] = EnrollmentWebhookCredential::createWebhook(
            tenantId: 1,
            name: 'Global '.$platform,
            productId: $course->id,
            platform: $platform,
            externalProductId: null,
            isActive: true,
        );

        return [
            'url' => '/api/webhooks/enrollment/'.$credential->webhook_key,
            'course' => $course,
            'mappedCourse' => $mappedCourse,
        ];
    }

    public function test_all_platforms_enroll_with_manual_webhook_course(): void
    {
        foreach ($this->platformCases() as $label => $case) {
            Mail::fake();

            ['url' => $url, 'course' => $course, 'mappedCourse' => $mappedCourse] = $this->createWebhookWithMapping(
                $case['platform'],
                $case['external_product_id'],
            );

            $email = $label.'-global-'.uniqid().'@example.com';
            $payload = ($case['build'])($email);

            $this->postJson($url, $payload)
                ->assertOk()
                ->assertJson([
                    'success' => true,
                    'action' => 'enrolled',
                    'course_id' => (string) $course->id,
                    'email_sent' => true,
                ]);

            $user = User::query()->where('email', $email)->first();
            $this->assertNotNull($user, "Aluno não criado para {$label}");
            $this->assertTrue(
                $course->users()->where('users.id', $user->id)->exists(),
                "Curso manual não liberado para {$label}",
            );
            $this->assertFalse(
                $mappedCourse->users()->where('users.id', $user->id)->exists(),
                "Curso mapeado liberado indevidamente para {$label}",
            );
        }
    }

    public function test_all_platforms_resend_email_for_existing_student_and_same_course(): void
    {
        Http::fake(['https://example.com/global-outbound' => Http::response('ok', 200)]);

        foreach ($this->platformCases() as $label => $case) {
            Mail::fake();
            Http::fake(['https://example.com/global-outbound' => Http::response('ok', 200)]);

            ['url' => $url, 'course' => $course] = $this->createWebhookWithMapping(
                $case['platform'],
                $case['external_product_id'],
            );

            Webhook::query()->where('url', 'https://example.com/global-outbound')->delete();
            $outbound = Webhook::create([
                'tenant_id' => 1,
                'name' => 'Outbound '.$label,
                'url' => 'https://example.com/global-outbound',
                'events' => [AccessDeliveryReady::class],
                'is_active' => true,
            ]);
            $outbound->products()->sync([$course->id]);

            $email = $label.'-resend-'.uniqid().'@example.com';
            $payload = ($case['build'])($email);

            $this->postJson($url, $payload)->assertOk()->assertJson(['action' => 'enrolled']);

            $this->postJson($url, $payload)
                ->assertOk()
                ->assertJson([
                    'duplicate' => true,
                    'email_sent' => true,
                ]);

            Mail::assertSent(AccessGrantedMail::class, 2);

            Http::assertSent(function ($request) use ($email, $course) {
                if ($request->url() !== 'https://example.com/global-outbound') {
                    return false;
                }

                $body = json_decode($request->body(), true);
                $payload = $body['payload'] ?? [];

                return ($body['event'] ?? '') === 'envio_acesso'
                    && ($payload['student']['email'] ?? '') === $email
                    && (string) ($payload['product']['id'] ?? '') === (string) $course->id
                    && ($payload['source'] ?? '') === 'enrollment_webhook'
                    && array_key_exists('phone', $payload['student'] ?? []);
            });
        }
    }

    public function test_existing_student_receives_email_and_outbound_on_resend(): void
    {
        Mail::fake();
        Http::fake(['https://example.com/existing-global' => Http::response('ok', 200)]);

        $course = $this->createMemberCourse();
        Webhook::create([
            'tenant_id' => 1,
            'name' => 'Outbound Existing',
            'url' => 'https://example.com/existing-global',
            'events' => [AccessDeliveryReady::class],
            'is_active' => true,
        ])->products()->sync([$course->id]);

        $user = User::factory()->create([
            'email' => 'existing-global-'.uniqid().'@example.com',
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
            'phone' => '5511999001122',
        ]);
        $course->users()->attach($user->id);

        ['model' => $credential] = EnrollmentWebhookCredential::createWebhook(
            tenantId: 1,
            name: 'Canonical Existing',
            productId: $course->id,
            platform: 'manual',
            externalProductId: null,
            isActive: true,
        );

        $payload = [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => '5511999001122',
            'platform' => 'manual',
            'event' => 'purchase_approved',
            'transaction_id' => 'tx-existing-global-'.uniqid(),
        ];

        $this->postJson('/api/webhooks/enrollment/'.$credential->webhook_key, $payload)
            ->assertOk()
            ->assertJson(['duplicate' => true, 'email_sent' => true]);

        Mail::assertSent(AccessGrantedMail::class, 1);
        Http::assertSentCount(1);
    }
}
