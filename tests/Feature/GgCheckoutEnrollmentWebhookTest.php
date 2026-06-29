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

class GgCheckoutEnrollmentWebhookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutDefer();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function ggCheckoutPayload(array $overrides = []): array
    {
        $base = [
            'event' => 'pix.paid',
            'createdAt' => '2024-01-15T10:30:00Z',
            'customer' => [
                'name' => 'Joao Silva',
                'email' => 'joao@email.com',
                'document' => '12345678901',
                'phone' => '5511999999999',
            ],
            'payment' => [
                'id' => '29cce702-5e7e-40da-93b0-aaa19acab32e',
                'method' => 'pix.paid',
                'paymentMethod' => 'pix',
                'status' => 'paid',
                'amount' => 97.00,
            ],
            'product' => [
                'id' => 'YbfsgK1Fgm0LzUsFglrn',
                'type' => 'main',
                'title' => 'Meu Produto Digital',
            ],
            'products' => [
                ['id' => 'YbfsgK1Fgm0LzUsFglrn', 'type' => 'main', 'title' => 'Meu Produto Digital'],
            ],
        ];

        return array_replace_recursive($base, $overrides);
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
     * @return array{issued: array<string, mixed>, course: Product, url: string}
     */
    private function createGgWebhook(?Product $course = null): array
    {
        $course ??= $this->createMemberCourse(['name' => 'Curso GG Painel']);

        $issued = EnrollmentWebhookCredential::createWebhook(
            tenantId: 1,
            name: 'GG Checkout',
            productId: $course->id,
            platform: 'gg_checkout',
            externalProductId: null,
            isActive: true,
        );

        return [
            'issued' => $issued,
            'course' => $course,
            'url' => '/api/webhooks/enrollment/'.$issued['model']->webhook_key,
        ];
    }

    public function test_gg_checkout_webhook_enrolls_with_manual_course(): void
    {
        Mail::fake();

        ['course' => $course, 'url' => $url] = $this->createGgWebhook();
        $email = 'gg-enroll-'.uniqid().'@example.com';

        $this->postJson($url, $this->ggCheckoutPayload([
            'customer' => [
                'name' => 'Maria GG',
                'email' => $email,
                'phone' => '5511888777666',
                'document' => '98765432100',
            ],
            'payment' => ['id' => 'pay-'.uniqid()],
        ]))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'action' => 'enrolled',
                'course_id' => (string) $course->id,
                'email_sent' => true,
            ]);

        $user = User::query()->where('email', $email)->first();
        $this->assertNotNull($user);
        $this->assertSame('Maria GG', $user->name);
        $this->assertSame('5511888777666', $user->phone);
        $this->assertTrue($course->users()->where('users.id', $user->id)->exists());
    }

    public function test_gg_checkout_enrolls_with_non_paid_status_when_email_present(): void
    {
        Mail::fake();

        ['course' => $course, 'url' => $url] = $this->createGgWebhook();
        $email = 'gg-pending-'.uniqid().'@example.com';

        $this->postJson($url, $this->ggCheckoutPayload([
            'customer' => ['name' => 'Aluno Pending', 'email' => $email],
            'payment' => ['id' => 'pay-pending-'.uniqid(), 'status' => 'pending'],
        ]))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'action' => 'enrolled',
                'course_id' => (string) $course->id,
            ]);
    }

    public function test_gg_checkout_enrolls_with_non_pix_event_when_email_present(): void
    {
        Mail::fake();

        ['course' => $course, 'url' => $url] = $this->createGgWebhook();
        $email = 'gg-generated-'.uniqid().'@example.com';

        $this->postJson($url, $this->ggCheckoutPayload([
            'event' => 'pix.generated',
            'customer' => ['name' => 'Aluno Generated', 'email' => $email],
            'payment' => ['id' => 'pay-gen-'.uniqid(), 'status' => 'waiting_payment'],
        ]))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'action' => 'enrolled',
                'course_id' => (string) $course->id,
            ]);
    }

    public function test_gg_checkout_product_id_does_not_override_manual_webhook_course(): void
    {
        Mail::fake();

        $courseWebhook = $this->createMemberCourse(['name' => 'Curso Manual GG']);
        $courseMapped = $this->createMemberCourse(['name' => 'Curso Mapeado GG']);

        EnrollmentExternalProductMapping::query()->create([
            'tenant_id' => 1,
            'platform' => 'gg_checkout',
            'external_product_id' => 'YbfsgK1Fgm0LzUsFglrn',
            'product_id' => $courseMapped->id,
        ]);

        ['url' => $url] = $this->createGgWebhook($courseWebhook);
        $email = 'gg-manual-'.uniqid().'@example.com';

        $this->postJson($url, $this->ggCheckoutPayload([
            'customer' => ['name' => 'Aluno Manual', 'email' => $email],
            'product' => ['id' => 'YbfsgK1Fgm0LzUsFglrn', 'title' => 'Produto GG'],
        ]))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'action' => 'enrolled',
                'course_id' => (string) $courseWebhook->id,
            ]);

        $user = User::query()->where('email', $email)->first();
        $this->assertTrue($courseWebhook->users()->where('users.id', $user->id)->exists());
        $this->assertFalse($courseMapped->users()->where('users.id', $user->id)->exists());
    }

    public function test_gg_checkout_existing_student_resends_email(): void
    {
        Mail::fake();
        Http::fake(['https://example.com/gg-outbound' => Http::response('ok', 200)]);

        $course = $this->createMemberCourse();
        Webhook::create([
            'tenant_id' => 1,
            'name' => 'Outbound GG',
            'url' => 'https://example.com/gg-outbound',
            'events' => [AccessDeliveryReady::class],
            'is_active' => true,
        ])->products()->sync([$course->id]);

        $user = User::factory()->create([
            'email' => 'gg-existing-'.uniqid().'@example.com',
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
            'phone' => '5511999887766',
        ]);
        $course->users()->attach($user->id);

        ['url' => $url] = $this->createGgWebhook($course);

        $this->postJson($url, $this->ggCheckoutPayload([
            'customer' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => '5511999887766',
            ],
            'payment' => ['id' => 'pay-existing-'.uniqid(), 'status' => 'paid'],
        ]))
            ->assertOk()
            ->assertJson(['duplicate' => true, 'email_sent' => true]);

        Mail::assertSent(AccessGrantedMail::class, 1);
        Http::assertSentCount(1);
    }

    public function test_gg_checkout_same_course_resends_email_on_replay(): void
    {
        Mail::fake();
        Http::fake(['https://example.com/gg-replay' => Http::response('ok', 200)]);

        $course = $this->createMemberCourse();
        Webhook::create([
            'tenant_id' => 1,
            'name' => 'Outbound GG Replay',
            'url' => 'https://example.com/gg-replay',
            'events' => [AccessDeliveryReady::class],
            'is_active' => true,
        ])->products()->sync([$course->id]);

        ['url' => $url] = $this->createGgWebhook($course);
        $email = 'gg-replay-'.uniqid().'@example.com';
        $payload = $this->ggCheckoutPayload([
            'customer' => ['name' => 'Replay GG', 'email' => $email, 'phone' => '5511777666555'],
            'payment' => ['id' => 'pay-replay-fixed', 'status' => 'paid'],
        ]);

        $this->postJson($url, $payload)->assertOk();
        $this->postJson($url, $payload)
            ->assertOk()
            ->assertJson(['duplicate' => true, 'email_sent' => true]);

        Mail::assertSent(AccessGrantedMail::class, 2);
        Http::assertSentCount(2);
    }

    public function test_gg_checkout_outbound_includes_student_phone_after_email(): void
    {
        Mail::fake();
        Http::fake(['https://example.com/gg-phone-outbound' => Http::response('ok', 200)]);

        $course = $this->createMemberCourse();
        Webhook::create([
            'tenant_id' => 1,
            'name' => 'Outbound GG Phone',
            'url' => 'https://example.com/gg-phone-outbound',
            'events' => [AccessDeliveryReady::class],
            'is_active' => true,
        ])->products()->sync([$course->id]);

        ['url' => $url] = $this->createGgWebhook($course);
        $email = 'gg-phone-'.uniqid().'@example.com';
        $phone = '5511666555444';
        $transactionId = 'pay-phone-'.uniqid();

        $this->postJson($url, $this->ggCheckoutPayload([
            'customer' => ['name' => 'Phone GG', 'email' => $email, 'phone' => $phone],
            'payment' => ['id' => $transactionId, 'status' => 'paid'],
        ]))->assertOk()->assertJson(['email_sent' => true]);

        Http::assertSent(function ($request) use ($email, $phone, $course, $transactionId) {
            if ($request->url() !== 'https://example.com/gg-phone-outbound') {
                return false;
            }

            $body = json_decode($request->body(), true);
            $payload = $body['payload'] ?? [];

            return ($body['event'] ?? '') === 'envio_acesso'
                && ($payload['student']['email'] ?? '') === $email
                && ($payload['student']['phone'] ?? null) === $phone
                && (string) ($payload['product']['id'] ?? '') === (string) $course->id
                && ($payload['source'] ?? '') === 'enrollment_webhook'
                && ($payload['transaction_id'] ?? '') === $transactionId;
        });
    }
}
