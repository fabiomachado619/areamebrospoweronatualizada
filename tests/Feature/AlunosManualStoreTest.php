<?php

namespace Tests\Feature;

use App\Events\AccessDeliveryReady;
use App\Http\Middleware\EnsureDockerSetup;
use App\Http\Middleware\EnsureInstalled;
use App\Mail\AccessGrantedMail;
use App\Models\Product;
use App\Models\User;
use App\Models\Webhook;
use App\Services\CheckoutStudentProvisioningService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AlunosManualStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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

    private function createMemberCourse(int $tenantId = 1): Product
    {
        return $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'tenant_id' => $tenantId,
            'checkout_slug' => 'curso-'.substr(uniqid('', true), -8),
            'checkout_config' => [
                'email_template' => Product::defaultEmailTemplate(),
            ],
        ]);
    }

    public function test_manual_store_creates_student_with_default_password_without_password_field(): void
    {
        Mail::fake();

        $owner = $this->infoprodutor();
        $course = $this->createMemberCourse();
        $email = 'manual-store-'.uniqid().'@example.com';

        $response = $this->actingAs($owner)->postJson(route('alunos.store'), [
            'name' => 'Aluno Manual',
            'email' => $email,
            'product_ids' => [(string) $course->id],
            'send_access_email' => false,
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $user = User::query()->where('email', $email)->first();
        $this->assertNotNull($user);
        $this->assertSame(User::ROLE_ALUNO, $user->role);
        $this->assertTrue(Hash::check(CheckoutStudentProvisioningService::DEFAULT_PASSWORD, $user->password));
        $this->assertNull($user->phone);
        $this->assertTrue($user->products()->where('products.id', $course->id)->exists());

        Mail::assertNothingSent();
    }

    public function test_manual_store_ignores_password_sent_in_request(): void
    {
        $owner = $this->infoprodutor();
        $email = 'manual-ignore-pwd-'.uniqid().'@example.com';

        $this->actingAs($owner)->postJson(route('alunos.store'), [
            'name' => 'Aluno Manual',
            'email' => $email,
            'password' => 'SenhaCustomizada99',
            'send_access_email' => false,
        ])->assertOk();

        $user = User::query()->where('email', $email)->firstOrFail();
        $this->assertTrue(Hash::check(CheckoutStudentProvisioningService::DEFAULT_PASSWORD, $user->password));
        $this->assertFalse(Hash::check('SenhaCustomizada99', $user->password));
    }

    public function test_manual_store_saves_phone_when_provided(): void
    {
        $owner = $this->infoprodutor();
        $email = 'manual-phone-'.uniqid().'@example.com';

        $this->actingAs($owner)->postJson(route('alunos.store'), [
            'name' => 'Aluno Com Telefone',
            'email' => $email,
            'phone' => '(65) 99288-7777',
            'send_access_email' => false,
        ])->assertOk();

        $user = User::query()->where('email', $email)->firstOrFail();
        $this->assertSame('(65) 99288-7777', $user->phone);
    }

    public function test_manual_store_sends_access_email_with_default_password_and_outbound(): void
    {
        Mail::fake();
        Http::fake(['https://example.com/manual-grant' => Http::response('ok', 200)]);

        $owner = $this->infoprodutor();
        $course = $this->createMemberCourse();

        $webhook = Webhook::create([
            'tenant_id' => 1,
            'name' => 'Outbound manual grant',
            'url' => 'https://example.com/manual-grant',
            'events' => [AccessDeliveryReady::class],
            'is_active' => true,
        ]);
        $webhook->products()->sync([$course->id]);

        $email = 'manual-email-'.uniqid().'@example.com';

        $this->actingAs($owner)->postJson(route('alunos.store'), [
            'name' => 'Aluno E-mail',
            'email' => $email,
            'phone' => '(11) 98877-6655',
            'product_ids' => [(string) $course->id],
            'send_access_email' => true,
        ])->assertOk();

        Mail::assertSent(AccessGrantedMail::class, function (AccessGrantedMail $mail) {
            return str_contains($mail->htmlBody, CheckoutStudentProvisioningService::DEFAULT_PASSWORD)
                && str_contains($mail->htmlBody, 'Senha inicial:');
        });

        Http::assertSent(function ($request) use ($email) {
            $payload = json_decode($request->body(), true)['payload'] ?? [];

            return ($payload['source'] ?? '') === 'manual_grant'
                && ($payload['student']['email'] ?? '') === $email
                && ($payload['student']['phone'] ?? '') === '5511988776655';
        });
    }

    public function test_manual_store_outbound_phone_is_null_when_not_provided(): void
    {
        Mail::fake();
        Http::fake(['https://example.com/manual-grant-null' => Http::response('ok', 200)]);

        $owner = $this->infoprodutor();
        $course = $this->createMemberCourse();

        $webhook = Webhook::create([
            'tenant_id' => 1,
            'name' => 'Outbound sem telefone',
            'url' => 'https://example.com/manual-grant-null',
            'events' => [AccessDeliveryReady::class],
            'is_active' => true,
        ]);
        $webhook->products()->sync([$course->id]);

        $email = 'manual-no-phone-'.uniqid().'@example.com';

        $this->actingAs($owner)->postJson(route('alunos.store'), [
            'name' => 'Aluno Sem Telefone',
            'email' => $email,
            'product_ids' => [(string) $course->id],
            'send_access_email' => true,
        ])->assertOk();

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true)['payload'] ?? [];

            return ($payload['source'] ?? '') === 'manual_grant'
                && array_key_exists('phone', $payload['student'] ?? [])
                && ($payload['student']['phone'] ?? null) === null;
        });
    }
}
