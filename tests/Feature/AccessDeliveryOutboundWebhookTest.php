<?php

namespace Tests\Feature;

use App\Events\AccessDeliveryReady;
use App\Mail\AccessGrantedMail;
use App\Models\EnrollmentWebhookCredential;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Webhook;
use App\Services\AccessEmailService;
use App\Support\WebhookPayloadBuilder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AccessDeliveryOutboundWebhookTest extends TestCase
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
     * @return array{webhook: Webhook, courseA: Product, courseB: Product}
     */
    private function createOutboundWebhookForCourseA(): array
    {
        $courseA = $this->createMemberCourse(['name' => 'Curso A']);
        $courseB = $this->createMemberCourse(['name' => 'Curso B']);

        $webhook = Webhook::create([
            'tenant_id' => 1,
            'name' => 'WhatsApp Curso A',
            'url' => 'https://example.com/outbound-access',
            'events' => [AccessDeliveryReady::class],
            'is_active' => true,
        ]);
        $webhook->products()->sync([$courseA->id]);

        return ['webhook' => $webhook, 'courseA' => $courseA, 'courseB' => $courseB];
    }

    public function test_enrollment_webhook_sends_email_and_outbound_for_single_student(): void
    {
        Mail::fake();
        Http::fake(['https://example.com/outbound-access' => Http::response('ok', 200)]);

        ['courseA' => $course] = $this->createOutboundWebhookForCourseA();

        $issued = EnrollmentWebhookCredential::createWebhook(
            tenantId: 1,
            name: 'Matrícula',
            productId: $course->id,
            platform: 'kiwify',
            externalProductId: null,
            isActive: true,
        );

        $email = 'outbound-enroll-'.uniqid().'@example.com';
        $transactionId = 'tx-out-'.uniqid();

        $this->postJson('/api/webhooks/enrollment/'.$issued['model']->webhook_key, [
            'name' => 'João Outbound',
            'email' => $email,
            'platform' => 'kiwify',
            'event' => 'purchase_approved',
            'transaction_id' => $transactionId,
        ])->assertOk()->assertJson(['success' => true, 'action' => 'enrolled', 'email_sent' => true]);

        Mail::assertSent(AccessGrantedMail::class, 1);

        Http::assertSent(function ($request) use ($email, $course, $transactionId) {
            if ($request->url() !== 'https://example.com/outbound-access') {
                return false;
            }

            $body = json_decode($request->body(), true);
            $payload = $body['payload'] ?? [];

            return ($body['event'] ?? '') === 'envio_acesso'
                && ($payload['student']['email'] ?? '') === $email
                && (string) ($payload['product']['id'] ?? '') === (string) $course->id
                && ($payload['source'] ?? '') === 'enrollment_webhook'
                && ($payload['transaction_id'] ?? '') === $transactionId;
        });
    }

    public function test_existing_student_same_course_resends_email_and_outbound(): void
    {
        Mail::fake();
        Http::fake(['https://example.com/outbound-access' => Http::response('ok', 200)]);

        ['courseA' => $course] = $this->createOutboundWebhookForCourseA();

        $user = User::factory()->create([
            'email' => 'existing-outbound-'.uniqid().'@example.com',
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $course->users()->attach($user->id);

        $issued = EnrollmentWebhookCredential::createWebhook(
            tenantId: 1,
            name: 'Reenvio',
            productId: $course->id,
            platform: 'kiwify',
            externalProductId: null,
            isActive: true,
        );

        $payload = [
            'name' => $user->name,
            'email' => $user->email,
            'platform' => 'kiwify',
            'event' => 'purchase_approved',
            'transaction_id' => 'tx-existing-'.uniqid(),
        ];

        $this->postJson('/api/webhooks/enrollment/'.$issued['model']->webhook_key, $payload)
            ->assertOk()
            ->assertJson(['duplicate' => true, 'email_sent' => true]);

        Mail::assertSent(AccessGrantedMail::class, 1);
        Http::assertSentCount(1);
    }

    public function test_identical_webhook_replay_resends_email_and_outbound(): void
    {
        Mail::fake();
        Http::fake(['https://example.com/outbound-access' => Http::response('ok', 200)]);

        ['courseA' => $course] = $this->createOutboundWebhookForCourseA();

        $issued = EnrollmentWebhookCredential::createWebhook(
            tenantId: 1,
            name: 'Replay',
            productId: $course->id,
            platform: 'kiwify',
            externalProductId: null,
            isActive: true,
        );

        $payload = [
            'name' => 'Replay User',
            'email' => 'replay-outbound-'.uniqid().'@example.com',
            'platform' => 'kiwify',
            'event' => 'purchase_approved',
            'transaction_id' => 'tx-replay-'.uniqid(),
        ];

        $url = '/api/webhooks/enrollment/'.$issued['model']->webhook_key;

        $this->postJson($url, $payload)->assertOk();
        $this->postJson($url, $payload)
            ->assertOk()
            ->assertJson(['duplicate' => true, 'email_sent' => true]);

        Mail::assertSent(AccessGrantedMail::class, 2);
        Http::assertSentCount(2);
    }

    public function test_manual_resend_dispatches_outbound_for_selected_student_only(): void
    {
        Mail::fake();
        Http::fake(['https://example.com/outbound-access' => Http::response('ok', 200)]);

        ['courseA' => $courseA] = $this->createOutboundWebhookForCourseA();

        $joao = User::factory()->create([
            'name' => 'João',
            'email' => 'joao-outbound-'.uniqid().'@example.com',
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
            'phone' => '65999998888',
        ]);

        $courseA->users()->attach($joao->id);

        app(AccessEmailService::class)->sendForUserProduct($joao, $courseA, null, ['source' => 'manual_resend']);

        Mail::assertSent(AccessGrantedMail::class, 1);

        Http::assertSent(function ($request) use ($joao, $courseA) {
            $body = json_decode($request->body(), true);
            $payload = $body['payload'] ?? [];

            return ($payload['student']['email'] ?? '') === $joao->email
                && (string) ($payload['product']['id'] ?? '') === (string) $courseA->id
                && ($payload['source'] ?? '') === 'manual_resend';
        });
        Http::assertSentCount(1);
    }

    public function test_outbound_product_filter_only_dispatches_matching_webhooks(): void
    {
        Http::fake([
            'https://example.com/outbound-access' => Http::response('ok', 200),
            'https://example.com/outbound-course-b' => Http::response('ok', 200),
        ]);

        ['courseA' => $courseA, 'courseB' => $courseB] = $this->createOutboundWebhookForCourseA();

        Webhook::create([
            'tenant_id' => 1,
            'name' => 'WhatsApp Curso B',
            'url' => 'https://example.com/outbound-course-b',
            'events' => [AccessDeliveryReady::class],
            'is_active' => true,
        ])->products()->sync([$courseB->id]);

        $user = User::factory()->create([
            'email' => 'filter-'.uniqid().'@example.com',
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $courseA->users()->attach($user->id);

        $access = app(AccessEmailService::class)->getAccessDataForUserProduct($user, $courseA);
        $this->assertIsArray($access);

        app(\App\Listeners\WebhookEventSubscriber::class)->handleEvent(new AccessDeliveryReady(
            order: null,
            access: $access,
            user: $user,
            product: $courseA,
            context: ['source' => 'manual_resend', 'sent_at' => now()->toIso8601String()],
        ));

        Http::assertSent(fn ($request) => $request->url() === 'https://example.com/outbound-access');
        Http::assertSentCount(1);
    }

    public function test_outbound_without_product_filter_dispatches_for_any_course(): void
    {
        Http::fake(['https://example.com/outbound-all' => Http::response('ok', 200)]);

        $course = $this->createMemberCourse(['name' => 'Curso Livre']);
        Webhook::create([
            'tenant_id' => 1,
            'name' => 'Todos os cursos',
            'url' => 'https://example.com/outbound-all',
            'events' => [AccessDeliveryReady::class],
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'email' => 'all-courses-'.uniqid().'@example.com',
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $course->users()->attach($user->id);

        $access = app(AccessEmailService::class)->getAccessDataForUserProduct($user, $course);
        $this->assertIsArray($access);

        AccessDeliveryReady::dispatch(
            order: null,
            access: $access,
            user: $user,
            product: $course,
            context: ['source' => 'manual_resend', 'sent_at' => now()->toIso8601String()],
        );

        Http::assertSent(fn ($request) => $request->url() === 'https://example.com/outbound-all');
    }

    public function test_pending_enrollment_still_sends_email_and_outbound_when_email_present(): void
    {
        Mail::fake();
        Http::fake(['https://example.com/outbound' => Http::response('ok', 200)]);

        $course = $this->createMemberCourse();
        Webhook::create([
            'tenant_id' => 1,
            'name' => 'Outbound',
            'url' => 'https://example.com/outbound',
            'events' => [AccessDeliveryReady::class],
            'is_active' => true,
        ])->products()->sync([$course->id]);

        $issued = EnrollmentWebhookCredential::createWebhook(
            tenantId: 1,
            name: 'Pendente',
            productId: $course->id,
            platform: 'poweron',
            externalProductId: null,
            isActive: true,
        );

        $this->postJson('/api/webhooks/enrollment/'.$issued['model']->webhook_key, [
            'body' => [
                'event' => 'pedido_pendente',
                'payload' => [
                    'order' => ['id' => 1, 'status' => 'pending'],
                    'customer' => [
                        'name' => 'Pendente',
                        'email' => 'pending-'.uniqid().'@example.com',
                    ],
                    'status' => 'pending',
                ],
            ],
        ])->assertOk()->assertJson(['action' => 'enrolled', 'email_sent' => true]);

        Mail::assertSent(AccessGrantedMail::class, 1);
        Http::assertSentCount(1);
    }

    public function test_probe_does_not_send_email_or_outbound(): void
    {
        Mail::fake();
        Http::fake();

        $course = $this->createMemberCourse();
        Webhook::create([
            'tenant_id' => 1,
            'name' => 'Outbound',
            'url' => 'https://example.com/outbound',
            'events' => [AccessDeliveryReady::class],
            'is_active' => true,
        ]);

        $issued = EnrollmentWebhookCredential::createWebhook(
            tenantId: 1,
            name: 'Probe',
            productId: $course->id,
            platform: 'wiapy',
            externalProductId: null,
            isActive: true,
        );

        $this->postJson('/api/webhooks/enrollment/'.$issued['model']->webhook_key, [])
            ->assertOk()
            ->assertJson(['action' => 'ignored']);

        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_invalid_email_does_not_dispatch_access_delivery_ready(): void
    {
        Event::fake([AccessDeliveryReady::class]);

        $course = $this->createMemberCourse();
        $user = User::factory()->create([
            'email' => 'invalid-email',
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $course->users()->attach($user->id);

        $sent = app(AccessEmailService::class)->sendForUserProduct($user, $course);

        $this->assertFalse($sent);
        Event::assertNotDispatched(AccessDeliveryReady::class);
    }

    public function test_enrollment_outbound_includes_student_phone_from_webhook_payload(): void
    {
        Mail::fake();
        Http::fake(['https://example.com/outbound-access' => Http::response('ok', 200)]);

        ['courseA' => $course] = $this->createOutboundWebhookForCourseA();

        $issued = EnrollmentWebhookCredential::createWebhook(
            tenantId: 1,
            name: 'Com Telefone',
            productId: $course->id,
            platform: 'poweron',
            externalProductId: null,
            isActive: true,
        );

        $email = 'phone-enroll-'.uniqid().'@example.com';

        $this->postJson('/api/webhooks/enrollment/'.$issued['model']->webhook_key, [
            'body' => [
                'event' => 'pedido_pago',
                'payload' => [
                    'order' => ['id' => 88001, 'status' => 'completed'],
                    'customer' => [
                        'name' => 'Aluno Telefone',
                        'email' => $email,
                        'phone' => '5511988776655',
                    ],
                    'status' => 'paid',
                    'payment' => ['gateway_transaction_id' => 'tx-phone-'.uniqid()],
                ],
            ],
        ])->assertOk();

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true)['payload'] ?? [];

            return ($payload['student']['phone'] ?? '') === '5511988776655';
        });
    }

    public function test_manual_resend_outbound_includes_student_phone(): void
    {
        Mail::fake();
        Http::fake(['https://example.com/outbound-access' => Http::response('ok', 200)]);

        ['courseA' => $courseA] = $this->createOutboundWebhookForCourseA();

        $user = User::factory()->create([
            'email' => 'manual-phone-'.uniqid().'@example.com',
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
            'phone' => '(65) 99288-7777',
        ]);
        $courseA->users()->attach($user->id);

        app(AccessEmailService::class)->sendForUserProduct($user, $courseA, null, ['source' => 'manual_resend']);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true)['payload'] ?? [];

            return ($payload['student']['phone'] ?? '') === '5565992887777';
        });
    }

    public function test_checkout_outbound_uses_order_phone_when_user_phone_is_empty(): void
    {
        Mail::fake();
        Http::fake(['https://example.com/outbound-access' => Http::response('ok', 200)]);

        ['courseA' => $course] = $this->createOutboundWebhookForCourseA();

        $user = User::factory()->create([
            'email' => 'checkout-phone-'.uniqid().'@example.com',
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
            'phone' => null,
        ]);
        $course->users()->attach($user->id);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $course->id,
            'status' => 'completed',
            'amount' => 97,
            'email' => $user->email,
            'phone' => '11912345678',
            'is_renewal' => false,
        ]);
        $order->load(['product', 'user']);

        app(AccessEmailService::class)->sendForOrder($order, true);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true)['payload'] ?? [];

            return ($payload['student']['phone'] ?? '') === '5511912345678';
        });
    }

    public function test_outbound_student_phone_is_null_when_unavailable_without_breaking_dispatch(): void
    {
        Http::fake(['https://example.com/outbound-access' => Http::response('ok', 200)]);

        $course = $this->createMemberCourse();
        $user = User::factory()->create([
            'email' => 'no-phone-'.uniqid().'@example.com',
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
            'phone' => null,
        ]);

        $payload = WebhookPayloadBuilder::forAccessDeliveryReady(
            $user,
            $course,
            ['link' => 'https://example.com/access', 'email' => $user->email, 'password' => '', 'type' => 'member_area', 'product_type' => Product::TYPE_AREA_MEMBROS],
            ['source' => 'manual_resend', 'sent_at' => now()->toIso8601String()],
        );

        $this->assertArrayHasKey('phone', $payload['student']);
        $this->assertNull($payload['student']['phone']);

        Webhook::create([
            'tenant_id' => 1,
            'name' => 'Sem Telefone',
            'url' => 'https://example.com/outbound-access',
            'events' => [AccessDeliveryReady::class],
            'is_active' => true,
        ]);

        app(\App\Listeners\WebhookEventSubscriber::class)->handleEvent(new AccessDeliveryReady(
            order: null,
            access: $payload['access'],
            user: $user,
            product: $course,
            context: ['source' => 'manual_resend', 'sent_at' => now()->toIso8601String()],
        ));

        Http::assertSentCount(1);
    }

    public function test_access_email_service_does_not_query_all_course_students(): void
    {
        $source = file_get_contents(app_path('Services/AccessEmailService.php'));

        $this->assertStringNotContainsString('->users()->get()', $source);
        $this->assertStringNotContainsString('->users()->each(', $source);
        $this->assertStringNotContainsString('foreach ($course->users', $source);
    }
}
