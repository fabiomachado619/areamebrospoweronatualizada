<?php

namespace Tests\Feature;

use App\Models\EnrollmentExternalProductMapping;
use App\Models\EnrollmentWebhookCredential;
use App\Models\EnrollmentWebhookLog;
use App\Models\MemberLesson;
use App\Models\MemberLessonProgress;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EnrollmentWebhookTest extends TestCase
{
    private string $plainToken;

    protected function setUp(): void
    {
        parent::setUp();

        ['plain_token' => $this->plainToken] = EnrollmentWebhookCredential::issueForTenant(1, 'test');
    }

    public function test_request_without_token_is_blocked(): void
    {
        $response = $this->postJson('/api/webhooks/enrollment', $this->basePayload());

        $response->assertUnauthorized()
            ->assertJson(['success' => false]);
    }

    public function test_cors_preflight_allows_unique_url_without_auth(): void
    {
        $course = $this->createMemberCourse();
        $issued = EnrollmentWebhookCredential::createWebhook(
            tenantId: 1,
            name: 'CORS',
            productId: $course->id,
            platform: 'kiwify',
            externalProductId: null,
            isActive: true,
        );

        $response = $this->call(
            'OPTIONS',
            '/api/webhooks/enrollment/'.$issued['model']->webhook_key,
            [],
            [],
            [],
            [
                'HTTP_ORIGIN' => 'https://app.n8n.cloud',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
            ]
        );

        $this->assertContains($response->getStatusCode(), [200, 204]);
        $response->assertHeader('Access-Control-Allow-Origin', '*');
        $this->assertStringContainsString('POST', (string) $response->headers->get('Access-Control-Allow-Methods'));
    }

    public function test_cors_preflight_allows_any_origin_without_auth(): void
    {
        $response = $this->call(
            'OPTIONS',
            '/api/webhooks/enrollment',
            [],
            [],
            [],
            [
                'HTTP_ORIGIN' => 'https://app.n8n.cloud',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization, content-type, x-signature',
            ]
        );

        $this->assertContains($response->getStatusCode(), [200, 204]);
        $response->assertHeader('Access-Control-Allow-Origin', '*');
        $this->assertStringContainsString('POST', (string) $response->headers->get('Access-Control-Allow-Methods'));
        $allowHeaders = strtolower((string) $response->headers->get('Access-Control-Allow-Headers'));
        $this->assertStringContainsString('authorization', $allowHeaders);
        $this->assertStringContainsString('content-type', $allowHeaders);
        $this->assertStringContainsString('x-signature', $allowHeaders);
    }

    public function test_post_response_includes_cors_headers_for_external_origin(): void
    {
        Mail::fake();

        $course = $this->createMemberCourse();

        $response = $this->postJson('/api/webhooks/enrollment', [
            'name' => 'CORS Test',
            'email' => 'cors-'.uniqid().'@test.local',
            'course_id' => $course->id,
            'platform' => 'kiwify',
            'event' => 'purchase_approved',
            'transaction_id' => 'tx-cors-'.uniqid(),
        ], [
            'Origin' => 'https://checkout.kiwify.com.br',
            'Authorization' => 'Bearer '.$this->plainToken,
        ]);

        $response->assertOk();
        $response->assertHeader('Access-Control-Allow-Origin', '*');
    }

    public function test_invalid_token_is_blocked(): void
    {
        $response = $this->postJson('/api/webhooks/enrollment', $this->basePayload(), [
            'Authorization' => 'Bearer invalid-token-'.str_repeat('x', 64),
        ]);

        $response->assertUnauthorized()
            ->assertJson(['success' => false]);
    }

    public function test_purchase_approved_creates_student_and_enrolls(): void
    {
        Mail::fake();

        $course = $this->createMemberCourse();

        $response = $this->postEnrollment([
            'email' => 'novo.aluno@example.com',
            'name' => 'Novo Aluno',
            'course_id' => $course->id,
            'transaction_id' => 'tx-create-'.uniqid(),
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'action' => 'enrolled',
                'course_id' => (string) $course->id,
                'email_sent' => true,
                'duplicate' => false,
            ]);

        $user = User::query()->where('email', 'novo.aluno@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame(User::ROLE_ALUNO, $user->role);
        $this->assertSame(1, $user->tenant_id);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check(
            \App\Services\EnrollmentWebhookService::DEFAULT_STUDENT_PASSWORD,
            $user->password
        ));
        $this->assertTrue($course->users()->where('users.id', $user->id)->exists());
        Mail::assertSent(\App\Mail\AccessGrantedMail::class);
    }

    public function test_purchase_approved_for_existing_student_grants_new_course(): void
    {
        Mail::fake();

        $courseA = $this->createMemberCourse(['name' => 'Curso A']);
        $courseB = $this->createMemberCourse(['name' => 'Curso B']);

        $aluno = User::factory()->create([
            'email' => 'existente@example.com',
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $oldHash = $aluno->password;
        $courseA->users()->attach($aluno->id);

        $response = $this->postEnrollment([
            'email' => 'existente@example.com',
            'name' => 'Nome Atualizado',
            'course_id' => $courseB->id,
            'transaction_id' => 'tx-existing-'.uniqid(),
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'action' => 'enrolled',
                'user_id' => $aluno->id,
                'course_id' => (string) $courseB->id,
                'email_sent' => true,
                'duplicate' => false,
            ]);

        $aluno->refresh();
        $this->assertSame('Nome Atualizado', $aluno->name);
        $this->assertSame($oldHash, $aluno->password);
        $this->assertTrue($courseB->users()->where('users.id', $aluno->id)->exists());
        Mail::assertSent(\App\Mail\AccessGrantedMail::class, 1);
    }

    public function test_same_course_with_different_transaction_returns_duplicate_without_email(): void
    {
        Mail::fake();

        $course = $this->createMemberCourse();
        $email = 'same-course-'.uniqid().'@example.com';

        $this->postEnrollment([
            'email' => $email,
            'name' => 'Mesmo Curso',
            'course_id' => $course->id,
            'transaction_id' => 'tx-first-'.uniqid(),
        ])->assertOk()->assertJson([
            'action' => 'enrolled',
            'email_sent' => true,
            'duplicate' => false,
        ]);

        Mail::assertSent(\App\Mail\AccessGrantedMail::class, 1);

        $response = $this->postEnrollment([
            'email' => $email,
            'name' => 'Mesmo Curso',
            'course_id' => $course->id,
            'transaction_id' => 'tx-second-'.uniqid(),
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'action' => EnrollmentWebhookLog::ACTION_DUPLICATE,
                'duplicate' => true,
                'email_sent' => false,
                'message' => 'Aluno já possuía acesso ao curso',
            ]);

        Mail::assertSent(\App\Mail\AccessGrantedMail::class, 1);

        $this->assertDatabaseHas('enrollment_webhook_logs', [
            'email' => $email,
            'course_id' => $course->id,
            'action' => EnrollmentWebhookLog::ACTION_DUPLICATE,
            'email_sent' => false,
        ]);
    }

    public function test_duplicate_event_does_not_resend_email(): void
    {
        Mail::fake();

        $course = $this->createMemberCourse();
        $transactionId = 'tx-dup-'.uniqid();

        $payload = [
            'email' => 'dup@example.com',
            'name' => 'Dup Test',
            'course_id' => $course->id,
            'transaction_id' => $transactionId,
        ];

        $this->postEnrollment($payload)->assertOk()->assertJson(['action' => 'enrolled']);

        $response = $this->postEnrollment($payload);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'duplicate' => true,
                'email_sent' => false,
                'message' => 'Evento já processado',
            ]);

        Mail::assertSent(\App\Mail\AccessGrantedMail::class, 1);
    }

    public function test_send_access_email_false_does_not_send_email(): void
    {
        Mail::fake();

        $course = $this->createMemberCourse();

        $response = $this->postEnrollment([
            'email' => 'sememail@example.com',
            'name' => 'Sem Email',
            'course_id' => $course->id,
            'transaction_id' => 'tx-no-mail-'.uniqid(),
            'send_access_email' => false,
        ]);

        $response->assertOk()
            ->assertJson([
                'action' => 'enrolled',
                'email_sent' => false,
            ]);

        Mail::assertNothingSent();
    }

    public function test_refund_removes_access_without_deleting_progress(): void
    {
        $course = $this->createMemberCourse();
        $aluno = User::factory()->create([
            'email' => 'revoke@example.com',
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $course->users()->attach($aluno->id);

        $section = MemberSection::create([
            'product_id' => $course->id,
            'title' => 'Módulo',
            'position' => 1,
            'section_type' => 'content',
        ]);
        $module = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $course->id,
            'title' => 'Aula 1',
            'position' => 1,
        ]);
        $lesson = MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $course->id,
            'title' => 'Lição',
            'position' => 1,
            'type' => MemberLesson::TYPE_TEXT,
        ]);
        $progress = MemberLessonProgress::create([
            'user_id' => $aluno->id,
            'member_lesson_id' => $lesson->id,
            'product_id' => $course->id,
            'completed_at' => now(),
            'progress_percent' => 100,
        ]);

        $response = $this->postEnrollment([
            'email' => 'revoke@example.com',
            'course_id' => $course->id,
            'event' => 'refund',
            'transaction_id' => 'tx-refund-'.uniqid(),
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'action' => 'revoked',
                'email_sent' => false,
            ]);

        $this->assertFalse($course->users()->where('users.id', $aluno->id)->exists());
        $this->assertNotNull(User::query()->find($aluno->id));
        $this->assertNotNull(MemberLessonProgress::query()->find($progress->id));
    }

    public function test_course_from_other_tenant_is_blocked(): void
    {
        $otherCourse = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'tenant_id' => 2,
            'checkout_slug' => 'outro-tenant-'.uniqid(),
        ]);

        $response = $this->postEnrollment([
            'email' => 'blocked@example.com',
            'name' => 'Blocked',
            'course_id' => $otherCourse->id,
            'transaction_id' => 'tx-block-'.uniqid(),
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);

        $this->assertStringContainsString('tenant', strtolower($response->json('message')));
    }

    public function test_external_product_resolves_via_mapping(): void
    {
        Mail::fake();

        $course = $this->createMemberCourse();
        EnrollmentExternalProductMapping::query()->create([
            'tenant_id' => 1,
            'platform' => 'kiwify',
            'external_product_id' => 'ext-prod-123',
            'product_id' => $course->id,
        ]);

        $response = $this->postEnrollment([
            'email' => 'mapped@example.com',
            'name' => 'Mapped User',
            'platform' => 'kiwify',
            'external_product_id' => 'ext-prod-123',
            'transaction_id' => 'tx-map-'.uniqid(),
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'action' => 'enrolled',
                'course_id' => (string) $course->id,
            ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function postEnrollment(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/webhooks/enrollment', array_merge($this->basePayload(), $overrides), [
            'Authorization' => 'Bearer '.$this->plainToken,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function basePayload(): array
    {
        return [
            'name' => 'Nome do Aluno',
            'email' => 'aluno@example.com',
            'phone' => '5599999999999',
            'document' => '00000000000',
            'platform' => 'kiwify',
            'event' => 'purchase_approved',
            'transaction_id' => 'abc123',
            'status' => 'approved',
            'send_access_email' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createMemberCourse(array $overrides = []): Product
    {
        return $this->createTestProduct(array_merge([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'curso-'.substr(uniqid('', true), -8),
            'name' => 'Curso Teste',
        ], $overrides));
    }
}
