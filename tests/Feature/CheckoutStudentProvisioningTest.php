<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDockerSetup;
use App\Http\Middleware\EnsureInstalled;
use App\Mail\AccessGrantedMail;
use App\Models\EnrollmentWebhookCredential;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\AccessEmailService;
use App\Services\CheckoutStudentProvisioningService;
use App\Services\EnrollmentWebhookService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CheckoutStudentProvisioningTest extends TestCase
{
    private string $webhookToken;

    protected function setUp(): void
    {
        parent::setUp();

        ['plain_token' => $this->webhookToken] = EnrollmentWebhookCredential::issueForTenant(1, 'checkout-pwd');
    }

    private function withoutInstallMiddleware(): void
    {
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureDockerSetup::class,
            ValidateCsrfToken::class,
        ]);
    }

    /**
     * @return array{hub: Product, course: Product, hubSlug: string}
     */
    private function createHubAndCourse(): array
    {
        $hubSlug = 'hub'.substr(uniqid('', true), -8);
        $courseSlug = 'cur'.substr(uniqid('', true), -8);

        $hub = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $hubSlug,
            'name' => 'HUB Checkout',
            'is_member_hub' => true,
        ]);

        $course = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $courseSlug,
            'name' => 'Curso Checkout',
            'member_hub_product_id' => $hub->id,
        ]);

        return ['hub' => $hub, 'course' => $course, 'hubSlug' => $hubSlug];
    }

    public function test_checkout_creates_new_student_with_default_password(): void
    {
        $ctx = $this->createHubAndCourse();
        $email = 'checkout-new-'.uniqid().'@test.local';

        $result = app(CheckoutStudentProvisioningService::class)->findOrCreateBuyer(
            $email,
            'Aluno Checkout',
            $ctx['course']
        );

        $user = $result['user'];
        $this->assertSame(User::ROLE_ALUNO, $user->role);
        $this->assertSame(1, $user->tenant_id);
        $this->assertTrue(Hash::check(CheckoutStudentProvisioningService::DEFAULT_PASSWORD, $user->password));
        $this->assertArrayHasKey('access_password_temp', $result['access_metadata']);
    }

    public function test_checkout_does_not_change_existing_student_password(): void
    {
        $ctx = $this->createHubAndCourse();
        $customPassword = 'SenhaAntigaCheckout99';

        $existing = User::factory()->create([
            'email' => 'checkout-existing@test.local',
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
            'password' => Hash::make($customPassword),
        ]);
        $oldHash = $existing->password;

        $result = app(CheckoutStudentProvisioningService::class)->findOrCreateBuyer(
            'checkout-existing@test.local',
            'Aluno Existente',
            $ctx['course']
        );

        $existing->refresh();
        $this->assertSame($oldHash, $existing->password);
        $this->assertTrue(Hash::check($customPassword, $existing->password));
        $this->assertFalse(Hash::check(CheckoutStudentProvisioningService::DEFAULT_PASSWORD, $existing->password));
        $this->assertSame([], $result['access_metadata']);
    }

    public function test_checkout_new_student_can_login_with_default_password(): void
    {
        $this->withoutInstallMiddleware();

        $ctx = $this->createHubAndCourse();
        $email = 'checkout-login-'.uniqid().'@test.local';

        $result = app(CheckoutStudentProvisioningService::class)->findOrCreateBuyer(
            $email,
            'Aluno Login Checkout',
            $ctx['course']
        );
        $ctx['course']->users()->attach($result['user']->id);

        $this->post('/m/'.$ctx['hubSlug'].'/login', [
            'email' => $email,
            'password' => CheckoutStudentProvisioningService::DEFAULT_PASSWORD,
        ])->assertRedirect('/m/'.$ctx['hubSlug']);

        $this->assertAuthenticatedAs($result['user']);
    }

    public function test_checkout_access_email_includes_initial_password_for_new_student(): void
    {
        Mail::fake();

        $ctx = $this->createHubAndCourse();
        $email = 'checkout-mail-'.uniqid().'@test.local';

        $result = app(CheckoutStudentProvisioningService::class)->findOrCreateBuyer(
            $email,
            'Aluno Email Checkout',
            $ctx['course']
        );
        $user = $result['user'];
        $ctx['course']->users()->attach($user->id);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $ctx['course']->id,
            'status' => 'completed',
            'amount' => 10,
            'email' => $email,
            'is_renewal' => false,
            'metadata' => $result['access_metadata'],
        ]);
        $order->load(['product', 'user']);

        $this->assertTrue(app(AccessEmailService::class)->sendForOrder($order, true));

        Mail::assertSent(AccessGrantedMail::class, function (AccessGrantedMail $mail) use ($email, $ctx) {
            return str_contains($mail->htmlBody, $email)
                && str_contains($mail->htmlBody, 'Senha inicial:')
                && str_contains($mail->htmlBody, CheckoutStudentProvisioningService::DEFAULT_PASSWORD)
                && str_contains($mail->htmlBody, '/m/'.$ctx['hubSlug'].'/login');
        });
    }

    public function test_checkout_access_email_for_existing_student_does_not_include_password(): void
    {
        Mail::fake();

        $ctx = $this->createHubAndCourse();
        $user = User::factory()->create([
            'email' => 'checkout-existing-mail@test.local',
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
            'password' => Hash::make('OutraSenha88'),
        ]);
        $ctx['course']->users()->attach($user->id);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $ctx['course']->id,
            'status' => 'completed',
            'amount' => 10,
            'email' => $user->email,
            'is_renewal' => false,
            'metadata' => [],
        ]);
        $order->load(['product', 'user']);

        $this->assertTrue(app(AccessEmailService::class)->sendForOrder($order, true));

        Mail::assertSent(AccessGrantedMail::class, function (AccessGrantedMail $mail) {
            return ! str_contains($mail->htmlBody, 'Senha inicial:')
                && ! str_contains($mail->htmlBody, CheckoutStudentProvisioningService::DEFAULT_PASSWORD);
        });
    }

    public function test_manual_admin_student_uses_chosen_password(): void
    {
        $this->withoutInstallMiddleware();

        $ctx = $this->createHubAndCourse();
        $password = 'SenhaEscolhidaPeloAdmin';

        $aluno = User::factory()->create([
            'email' => 'manual-admin@test.local',
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
            'password' => Hash::make($password),
        ]);
        $ctx['course']->users()->attach($aluno->id);

        $this->post('/m/'.$ctx['hubSlug'].'/login', [
            'email' => 'manual-admin@test.local',
            'password' => $password,
        ])->assertRedirect('/m/'.$ctx['hubSlug']);

        $this->assertAuthenticatedAs($aluno);
        $this->assertFalse(Hash::check(CheckoutStudentProvisioningService::DEFAULT_PASSWORD, $aluno->fresh()->password));
    }

    public function test_webhook_new_student_receives_default_password(): void
    {
        Mail::fake();

        $ctx = $this->createHubAndCourse();
        $email = 'webhook-new-'.uniqid().'@test.local';

        $this->postJson('/api/webhooks/enrollment', [
            'name' => 'Aluno Webhook',
            'email' => $email,
            'course_id' => $ctx['course']->id,
            'platform' => 'kiwify',
            'event' => 'purchase_approved',
            'transaction_id' => 'tx-wh-'.uniqid(),
        ], [
            'Authorization' => 'Bearer '.$this->webhookToken,
        ])->assertOk();

        $user = User::query()->where('email', $email)->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check(EnrollmentWebhookService::DEFAULT_STUDENT_PASSWORD, $user->password));
    }

    public function test_webhook_existing_student_email_does_not_include_password(): void
    {
        Mail::fake();

        $ctx = $this->createHubAndCourse();
        $courseB = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'curb'.substr(uniqid('', true), -8),
            'name' => 'Curso B Webhook',
            'member_hub_product_id' => $ctx['hub']->id,
        ]);

        $aluno = User::factory()->create([
            'email' => 'webhook-existing-mail@test.local',
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
            'password' => Hash::make('MinhaSenhaPersistida77'),
        ]);
        $ctx['course']->users()->attach($aluno->id);

        $this->postJson('/api/webhooks/enrollment', [
            'name' => 'Aluno Existente',
            'email' => 'webhook-existing-mail@test.local',
            'course_id' => $courseB->id,
            'platform' => 'kiwify',
            'event' => 'purchase_approved',
            'transaction_id' => 'tx-wh-existing-'.uniqid(),
        ], [
            'Authorization' => 'Bearer '.$this->webhookToken,
        ])->assertOk();

        Mail::assertSent(AccessGrantedMail::class, function (AccessGrantedMail $mail) {
            return ! str_contains($mail->htmlBody, 'Senha inicial:')
                && ! str_contains($mail->htmlBody, EnrollmentWebhookService::DEFAULT_STUDENT_PASSWORD);
        });
    }
}
