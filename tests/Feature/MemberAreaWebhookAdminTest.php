<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDockerSetup;
use App\Http\Middleware\EnsureInstalled;
use App\Models\EnrollmentWebhookCredential;
use App\Models\EnrollmentWebhookLog;
use App\Models\Product;
use App\Models\User;
use App\Services\EnrollmentWebhookAdminService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MemberAreaWebhookAdminTest extends TestCase
{
    private function withoutInstallMiddleware(): void
    {
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureDockerSetup::class,
            ValidateCsrfToken::class,
        ]);
    }

    private function infoprodutor(int $tenantId = 1): User
    {
        return User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => $tenantId,
        ]);
    }

    private function createCourse(int $tenantId = 1): Product
    {
        return $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'tenant_id' => $tenantId,
            'checkout_slug' => 'cur'.substr(uniqid('', true), -8),
            'name' => 'Curso Teste',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postWebhookByUrl(string $webhookKey, array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/webhooks/enrollment/'.$webhookKey, array_merge([
            'name' => 'Nome do Aluno',
            'email' => 'aluno@example.com',
            'platform' => 'kiwify',
            'event' => 'purchase_approved',
            'transaction_id' => 'tx-'.uniqid(),
            'status' => 'approved',
            'send_access_email' => true,
        ], $payload));
    }

    public function test_admin_can_create_webhook_with_unique_url(): void
    {
        $this->withoutInstallMiddleware();

        $owner = $this->infoprodutor();
        $course = $this->createCourse();

        $response = $this->actingAs($owner)->postJson('/area-membros-admin/webhooks', [
            'name' => 'Kiwify - Curso UPA',
            'product_id' => $course->id,
            'platform' => 'kiwify',
            'external_product_id' => 'ext-123',
            'is_active' => true,
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['webhook' => ['id', 'name', 'product_id', 'webhook_key', 'webhook_url']])
            ->assertJsonMissing(['plain_token']);

        $webhookUrl = $response->json('webhook.webhook_url');
        $this->assertStringContainsString('/api/webhooks/enrollment/', $webhookUrl);

        $this->assertDatabaseHas('enrollment_webhook_credentials', [
            'tenant_id' => 1,
            'name' => 'Kiwify - Curso UPA',
            'product_id' => $course->id,
            'platform' => 'kiwify',
            'is_active' => true,
        ]);
    }

    public function test_webhooks_tab_lists_webhooks_with_copy_ready_url(): void
    {
        $this->withoutInstallMiddleware();

        $owner = $this->infoprodutor();
        $course = $this->createCourse();

        app(EnrollmentWebhookAdminService::class)->createWebhook(1, [
            'name' => 'Webhook Listado',
            'product_id' => $course->id,
            'platform' => 'hotmart',
            'is_active' => true,
        ]);

        $response = $this->actingAs($owner)->get('/area-membros-admin?tab=webhooks');

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tab', 'webhooks')
                ->has('webhooks', 1)
                ->where('webhooks.0.name', 'Webhook Listado')
                ->has('webhooks.0.webhook_url')
                ->has('webhook_url_pattern')
            );
    }

    public function test_webhook_by_unique_url_grants_access(): void
    {
        Mail::fake();

        $course = $this->createCourse();
        $issued = EnrollmentWebhookCredential::createWebhook(
            tenantId: 1,
            name: 'Kiwify UPA',
            productId: $course->id,
            platform: 'kiwify',
            externalProductId: null,
            isActive: true,
        );

        $response = $this->postWebhookByUrl($issued['model']->webhook_key, [
            'name' => 'Aluno Webhook',
            'email' => 'webhook-url@example.com',
            'transaction_id' => 'tx-url-'.uniqid(),
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'action' => 'enrolled',
                'course_id' => (string) $course->id,
            ]);

        $user = User::query()->where('email', 'webhook-url@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($course->users()->where('users.id', $user->id)->exists());
    }

    public function test_webhook_enrolls_linked_course_without_course_id_in_payload(): void
    {
        Mail::fake();

        $course = $this->createCourse();
        $issued = EnrollmentWebhookCredential::createWebhook(
            tenantId: 1,
            name: 'Kiwify UPA',
            productId: $course->id,
            platform: 'kiwify',
            externalProductId: null,
            isActive: true,
        );

        $response = $this->postWebhookByUrl($issued['model']->webhook_key, [
            'name' => 'Aluno Webhook',
            'email' => 'webhook-linked@example.com',
            'transaction_id' => 'tx-linked-'.uniqid(),
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'action' => 'enrolled',
                'course_id' => (string) $course->id,
            ]);

        $user = User::query()->where('email', 'webhook-linked@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($course->users()->where('users.id', $user->id)->exists());
    }

    public function test_inactive_webhook_is_blocked(): void
    {
        $course = $this->createCourse();
        $issued = EnrollmentWebhookCredential::createWebhook(
            tenantId: 1,
            name: 'Inativo',
            productId: $course->id,
            platform: 'kiwify',
            externalProductId: null,
            isActive: false,
        );

        $response = $this->postWebhookByUrl($issued['model']->webhook_key, [
            'email' => 'blocked@example.com',
            'name' => 'Blocked',
            'transaction_id' => 'tx-inactive-'.uniqid(),
        ]);

        $response->assertForbidden()
            ->assertJson(['success' => false, 'message' => 'Webhook inativo.']);
    }

    public function test_unknown_webhook_key_is_blocked(): void
    {
        $response = $this->postWebhookByUrl('chaveinexistente1234567890', [
            'email' => 'unknown@example.com',
            'name' => 'Unknown',
            'transaction_id' => 'tx-unknown-'.uniqid(),
        ]);

        $response->assertNotFound()
            ->assertJson(['success' => false, 'message' => 'Webhook não encontrado.']);
    }

    public function test_payload_course_from_other_tenant_is_ignored_when_webhook_has_manual_course(): void
    {
        Mail::fake();

        $course = $this->createCourse();
        $issued = EnrollmentWebhookCredential::createWebhook(
            tenantId: 1,
            name: 'Tenant 1',
            productId: $course->id,
            platform: 'kiwify',
            externalProductId: null,
            isActive: true,
        );

        $otherCourse = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'tenant_id' => 2,
            'checkout_slug' => 'outro-tenant-'.uniqid(),
        ]);

        $response = $this->postWebhookByUrl($issued['model']->webhook_key, [
            'email' => 'blocked@example.com',
            'name' => 'Blocked',
            'course_id' => $otherCourse->id,
            'transaction_id' => 'tx-block-'.uniqid(),
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'course_id' => (string) $course->id,
            ]);

        $user = User::query()->where('email', 'blocked@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($course->users()->where('users.id', $user->id)->exists());
        $this->assertFalse($otherCourse->users()->where('users.id', $user->id)->exists());
    }

    public function test_other_tenant_cannot_update_webhook(): void
    {
        $this->withoutInstallMiddleware();

        $course = $this->createCourse(1);
        $created = app(EnrollmentWebhookAdminService::class)->createWebhook(1, [
            'name' => 'Tenant 1',
            'product_id' => $course->id,
            'platform' => 'kiwify',
            'is_active' => true,
        ]);

        $otherOwner = $this->infoprodutor(2);
        $otherCourse = $this->createCourse(2);

        $this->actingAs($otherOwner)->putJson('/area-membros-admin/webhooks/'.$created['webhook']['id'], [
            'name' => 'Hack',
            'product_id' => $otherCourse->id,
            'platform' => 'kiwify',
            'is_active' => true,
        ])->assertNotFound();
    }

    public function test_regenerate_url_invalidates_previous(): void
    {
        $this->withoutInstallMiddleware();

        $owner = $this->infoprodutor();
        $course = $this->createCourse();
        $created = app(EnrollmentWebhookAdminService::class)->createWebhook(1, [
            'name' => 'Rotate',
            'product_id' => $course->id,
            'platform' => 'kiwify',
            'is_active' => true,
        ]);

        $oldKey = EnrollmentWebhookCredential::query()->find($created['webhook']['id'])->webhook_key;

        $regen = $this->actingAs($owner)->postJson('/area-membros-admin/webhooks/'.$created['webhook']['id'].'/regenerate-url');
        $regen->assertOk()
            ->assertJsonStructure(['webhook' => ['webhook_url', 'webhook_key']])
            ->assertJsonMissing(['plain_token']);

        $newKey = $regen->json('webhook.webhook_key');
        $this->assertNotSame($oldKey, $newKey);

        $this->postWebhookByUrl($oldKey, [
            'email' => 'old-url@example.com',
            'name' => 'Old',
            'transaction_id' => 'tx-old-'.uniqid(),
        ])->assertNotFound();

        $this->postWebhookByUrl($newKey, [
            'email' => 'new-url@example.com',
            'name' => 'New',
            'transaction_id' => 'tx-new-'.uniqid(),
        ])->assertOk();
    }

    public function test_logs_appear_in_admin_tab(): void
    {
        $this->withoutInstallMiddleware();

        $owner = $this->infoprodutor();
        $course = $this->createCourse();
        $created = app(EnrollmentWebhookAdminService::class)->createWebhook(1, [
            'name' => 'Com Logs',
            'product_id' => $course->id,
            'platform' => 'kiwify',
            'is_active' => true,
        ]);

        EnrollmentWebhookLog::create([
            'tenant_id' => 1,
            'enrollment_webhook_id' => $created['webhook']['id'],
            'platform' => 'kiwify',
            'event' => 'purchase_approved',
            'email' => 'log@test.local',
            'course_id' => $course->id,
            'action' => EnrollmentWebhookLog::ACTION_ENROLLED,
            'email_sent' => true,
            'processed_at' => now(),
            'payload' => ['email' => 'log@test.local'],
        ]);

        $response = $this->actingAs($owner)->get('/area-membros-admin?tab=webhooks');

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('webhook_logs', 1)
                ->where('webhook_logs.0.email', 'log@test.local')
                ->where('webhook_logs.0.action', 'enrolled')
            );
    }

    public function test_webhook_request_saves_log(): void
    {
        Mail::fake();

        $course = $this->createCourse();
        $issued = EnrollmentWebhookCredential::createWebhook(
            tenantId: 1,
            name: 'Com Log',
            productId: $course->id,
            platform: 'kiwify',
            externalProductId: null,
            isActive: true,
        );

        $this->postWebhookByUrl($issued['model']->webhook_key, [
            'email' => 'log-save@example.com',
            'name' => 'Log Save',
            'transaction_id' => 'tx-log-'.uniqid(),
        ])->assertOk();

        $this->assertDatabaseHas('enrollment_webhook_logs', [
            'enrollment_webhook_id' => $issued['model']->id,
            'email' => 'log-save@example.com',
            'action' => EnrollmentWebhookLog::ACTION_ENROLLED,
        ]);
    }

    public function test_duplicate_payload_resends_email_on_replay_with_unique_url(): void
    {
        Mail::fake();

        $course = $this->createCourse();
        $issued = EnrollmentWebhookCredential::createWebhook(
            tenantId: 1,
            name: 'Dup',
            productId: $course->id,
            platform: 'kiwify',
            externalProductId: null,
            isActive: true,
        );

        $payload = [
            'name' => 'Dup User',
            'email' => 'dup-webhook@example.com',
            'platform' => 'kiwify',
            'event' => 'purchase_approved',
            'transaction_id' => 'tx-dup-wh-'.uniqid(),
        ];

        $this->postWebhookByUrl($issued['model']->webhook_key, $payload)->assertOk();

        $this->postWebhookByUrl($issued['model']->webhook_key, $payload)
            ->assertOk()
            ->assertJson(['duplicate' => true, 'email_sent' => true]);

        Mail::assertSent(\App\Mail\AccessGrantedMail::class, 2);
    }

    public function test_notascast_body_format_enrolls_via_unique_url(): void
    {
        Mail::fake();

        $course = $this->createCourse();
        $issued = EnrollmentWebhookCredential::createWebhook(
            tenantId: 1,
            name: 'Notascast',
            productId: $course->id,
            platform: 'notascast',
            externalProductId: null,
            isActive: true,
        );

        $response = $this->postJson('/api/webhooks/enrollment/'.$issued['model']->webhook_key, [
            'body' => [
                'name' => 'fabio machado',
                'whatsapp' => '+5565992976877',
                'email' => 'notascast-'.uniqid().'@example.com',
            ],
        ]);

        $response->assertOk()->assertJson(['success' => true, 'action' => 'enrolled']);
        Mail::assertSent(\App\Mail\AccessGrantedMail::class);
    }

    public function test_kiwify_body_format_enrolls_via_unique_url(): void
    {
        Mail::fake();

        $course = $this->createCourse();
        $issued = EnrollmentWebhookCredential::createWebhook(
            tenantId: 1,
            name: 'Kiwify',
            productId: $course->id,
            platform: 'kiwify',
            externalProductId: null,
            isActive: true,
        );

        $response = $this->postJson('/api/webhooks/enrollment/'.$issued['model']->webhook_key, [
            'body' => [
                'order_id' => 'order-'.uniqid(),
                'order_status' => 'paid',
                'webhook_event_type' => 'order_approved',
                'Product' => ['product_id' => 'ext-1', 'product_name' => 'Curso'],
                'Customer' => [
                    'full_name' => 'John Doe',
                    'email' => 'kiwify-'.uniqid().'@example.com',
                    'mobile' => '+5511999999999',
                ],
            ],
        ]);

        $response->assertOk()->assertJson(['success' => true, 'action' => 'enrolled']);
    }

    public function test_hotmart_body_format_enrolls_via_unique_url(): void
    {
        Mail::fake();

        $course = $this->createCourse();
        $issued = EnrollmentWebhookCredential::createWebhook(
            tenantId: 1,
            name: 'Hotmart',
            productId: $course->id,
            platform: 'hotmart',
            externalProductId: null,
            isActive: true,
        );

        $email = 'hotmart-'.uniqid().'@example.com';

        $response = $this->postJson('/api/webhooks/enrollment/'.$issued['model']->webhook_key, [
            'body' => [
                'event' => 'PURCHASE_COMPLETE',
                'data' => [
                    'product' => [
                        'id' => 0,
                        'ucode' => 'fb056612-bcc6-4217-9e6d-2a5d1110ac2f',
                        'name' => 'Produto Hotmart',
                    ],
                    'purchase' => [
                        'transaction' => 'HP'.uniqid(),
                        'status' => 'COMPLETED',
                    ],
                    'buyer' => [
                        'name' => 'Comprador Hotmart',
                        'email' => $email,
                        'checkout_phone' => '99999999900',
                    ],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'action' => EnrollmentWebhookLog::ACTION_ENROLLED,
                'course_id' => (string) $course->id,
            ]);

        $user = User::query()->where('email', $email)->first();
        $this->assertNotNull($user);
        $this->assertTrue($course->users()->where('users.id', $user->id)->exists());
        Mail::assertSent(\App\Mail\AccessGrantedMail::class);
    }

    public function test_missing_email_returns_clear_error(): void
    {
        $course = $this->createCourse();
        $issued = EnrollmentWebhookCredential::createWebhook(
            tenantId: 1,
            name: 'Sem Email',
            productId: $course->id,
            platform: 'notascast',
            externalProductId: null,
            isActive: true,
        );

        $response = $this->postJson('/api/webhooks/enrollment/'.$issued['model']->webhook_key, [
            'body' => [
                'name' => 'Sem Email',
                'whatsapp' => '+5565999999999',
            ],
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'E-mail não encontrado no payload.',
            ]);
    }

    public function test_poweron_pending_payload_enrolls_when_email_present(): void
    {
        Mail::fake();

        $course = $this->createCourse();
        $issued = EnrollmentWebhookCredential::createWebhook(
            tenantId: 1,
            name: 'Power On Pendente',
            productId: $course->id,
            platform: 'poweron',
            externalProductId: null,
            isActive: true,
        );

        $email = 'poweron-pending-'.uniqid().'@example.com';

        $response = $this->postJson('/api/webhooks/enrollment/'.$issued['model']->webhook_key, [
            'body' => [
                'event' => 'pedido_pendente',
                'payload' => [
                    'order' => [
                        'id' => 90002,
                        'status' => 'pending',
                    ],
                    'customer' => [
                        'name' => 'Cliente Pendente',
                        'email' => $email,
                        'phone' => '5511999999999',
                    ],
                    'status' => 'pending',
                    'payment' => [
                        'gateway_transaction_id' => 'tx-pending-'.uniqid(),
                    ],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'action' => EnrollmentWebhookLog::ACTION_ENROLLED,
                'email_sent' => true,
            ]);

        $user = User::query()->where('email', $email)->first();
        $this->assertNotNull($user);
        $this->assertTrue($course->users()->where('users.id', $user->id)->exists());

        Mail::assertSent(\App\Mail\AccessGrantedMail::class, 1);
    }

    public function test_poweron_paid_payload_enrolls_via_unique_url(): void
    {
        Mail::fake();

        $course = $this->createCourse();
        $issued = EnrollmentWebhookCredential::createWebhook(
            tenantId: 1,
            name: 'Power On Pago',
            productId: $course->id,
            platform: 'poweron',
            externalProductId: null,
            isActive: true,
        );

        $email = 'poweron-paid-'.uniqid().'@example.com';

        $response = $this->postJson('/api/webhooks/enrollment/'.$issued['model']->webhook_key, [
            'body' => [
                'event' => 'pedido_pago',
                'payload' => [
                    'order' => [
                        'id' => 90003,
                        'status' => 'completed',
                    ],
                    'customer' => [
                        'name' => 'Cliente Pago',
                        'email' => $email,
                        'phone' => '5511888888888',
                    ],
                    'status' => 'paid',
                    'payment' => [
                        'gateway_transaction_id' => 'tx-paid-'.uniqid(),
                    ],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'action' => EnrollmentWebhookLog::ACTION_ENROLLED,
                'course_id' => (string) $course->id,
            ]);

        $user = User::query()->where('email', $email)->first();
        $this->assertNotNull($user);
        $this->assertTrue($course->users()->where('users.id', $user->id)->exists());

        Mail::assertSent(\App\Mail\AccessGrantedMail::class);
    }

    public function test_probe_empty_body_returns_200_without_enrollment(): void
    {
        Mail::fake();

        $course = $this->createCourse();
        $issued = EnrollmentWebhookCredential::createWebhook(
            tenantId: 1,
            name: 'Probe Empty',
            productId: $course->id,
            platform: 'wiapy',
            externalProductId: null,
            isActive: true,
        );

        $response = $this->postJson('/api/webhooks/enrollment/'.$issued['model']->webhook_key, []);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Webhook received',
                'action' => EnrollmentWebhookLog::ACTION_IGNORED,
            ]);

        $this->assertSame(0, User::query()->count());
        Mail::assertNothingSent();
    }

    public function test_probe_event_test_returns_200_without_enrollment(): void
    {
        Mail::fake();

        $course = $this->createCourse();
        $issued = EnrollmentWebhookCredential::createWebhook(
            tenantId: 1,
            name: 'Probe Test',
            productId: $course->id,
            platform: 'wiapy',
            externalProductId: null,
            isActive: true,
        );

        $response = $this->postJson('/api/webhooks/enrollment/'.$issued['model']->webhook_key, [
            'event' => 'test',
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Webhook received',
                'action' => EnrollmentWebhookLog::ACTION_IGNORED,
            ]);

        Mail::assertNothingSent();
        $this->assertFalse($course->users()->exists());
    }

    public function test_probe_event_ping_and_webhook_test_return_200(): void
    {
        Mail::fake();

        $course = $this->createCourse();
        $issued = EnrollmentWebhookCredential::createWebhook(
            tenantId: 1,
            name: 'Probe Ping',
            productId: $course->id,
            platform: 'wiapy',
            externalProductId: null,
            isActive: true,
        );

        foreach (['ping', 'webhook.test'] as $event) {
            $this->postJson('/api/webhooks/enrollment/'.$issued['model']->webhook_key, [
                'event' => $event,
            ])->assertOk()->assertJson([
                'success' => true,
                'message' => 'Webhook received',
            ]);
        }

        Mail::assertNothingSent();
    }

    public function test_wiapy_partial_payload_without_email_returns_422(): void
    {
        Mail::fake();

        $course = $this->createCourse();
        $issued = EnrollmentWebhookCredential::createWebhook(
            tenantId: 1,
            name: 'Wiapy Partial',
            productId: $course->id,
            platform: 'wiapy',
            externalProductId: null,
            isActive: true,
        );

        $response = $this->postJson('/api/webhooks/enrollment/'.$issued['model']->webhook_key, [
            'data' => [
                'payment' => ['status' => 'paid'],
                'customer' => [],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'E-mail não encontrado no payload.',
            ]);

        Mail::assertNothingSent();
        $this->assertFalse($course->users()->exists());
    }

    public function test_get_and_head_webhook_url_return_200(): void
    {
        $course = $this->createCourse();
        $issued = EnrollmentWebhookCredential::createWebhook(
            tenantId: 1,
            name: 'Probe GET',
            productId: $course->id,
            platform: 'wiapy',
            externalProductId: null,
            isActive: true,
        );

        $url = '/api/webhooks/enrollment/'.$issued['model']->webhook_key;

        $this->getJson($url)->assertOk()->assertJson([
            'success' => true,
            'message' => 'Webhook received',
        ]);

        $this->call('HEAD', $url)->assertOk();
    }

    public function test_wiapy_paid_payload_enrolls_via_unique_url(): void
    {
        Mail::fake();

        $course = $this->createCourse();
        $issued = EnrollmentWebhookCredential::createWebhook(
            tenantId: 1,
            name: 'Wiapy Pago',
            productId: $course->id,
            platform: 'wiapy',
            externalProductId: null,
            isActive: true,
        );

        $email = 'wiapy-paid-'.uniqid().'@example.com';

        $response = $this->postJson('/api/webhooks/enrollment/'.$issued['model']->webhook_key, [
            'data' => [
                'payment' => [
                    'id' => 'pay-'.uniqid(),
                    'status' => 'paid',
                ],
                'customer' => [
                    'name' => 'Cliente Wiapy',
                    'email' => $email,
                    'mobile_phone' => '(11) 99999-9999',
                ],
                'products' => [
                    ['id' => 'prod-wiapy-1', 'title' => 'Curso Wiapy'],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'action' => EnrollmentWebhookLog::ACTION_ENROLLED,
                'course_id' => (string) $course->id,
            ]);

        $user = User::query()->where('email', $email)->first();
        $this->assertNotNull($user);
        $this->assertTrue($course->users()->where('users.id', $user->id)->exists());
        Mail::assertSent(\App\Mail\AccessGrantedMail::class);
    }
}
