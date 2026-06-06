<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDockerSetup;
use App\Http\Middleware\EnsureInstalled;
use App\Mail\AccessGrantedMail;
use App\Models\EnrollmentWebhookCredential;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use App\Services\EnrollmentWebhookService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MemberAreaStudentAccessTest extends TestCase
{
    private string $plainToken;

    protected function setUp(): void
    {
        parent::setUp();

        ['plain_token' => $this->plainToken] = EnrollmentWebhookCredential::issueForTenant(1, 'student-access');
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
     * @return array{hub: Product, course: Product, hubSlug: string, courseSlug: string}
     */
    private function createHubAndCourse(): array
    {
        $hubSlug = 'hub'.substr(uniqid('', true), -8);
        $courseSlug = 'cur'.substr(uniqid('', true), -8);

        $hub = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $hubSlug,
            'name' => 'HUB Teste',
            'is_member_hub' => true,
        ]);

        $course = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $courseSlug,
            'name' => 'Curso Teste',
            'member_hub_product_id' => $hub->id,
        ]);

        return compact('hub', 'course', 'hubSlug', 'courseSlug');
    }

    public function test_manual_student_can_login_to_member_area(): void
    {
        $this->withoutInstallMiddleware();

        $ctx = $this->createHubAndCourse();
        $password = 'SenhaManual99';

        $aluno = User::factory()->create([
            'email' => 'manual-login@test.local',
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
            'password' => Hash::make($password),
        ]);
        $ctx['course']->users()->attach($aluno->id);

        $this->post('/m/'.$ctx['hubSlug'].'/login', [
            'email' => 'manual-login@test.local',
            'password' => $password,
        ])->assertRedirect('/m/'.$ctx['hubSlug']);

        $this->assertAuthenticatedAs($aluno);
    }

    public function test_webhook_new_student_can_login_with_default_password(): void
    {
        $this->withoutInstallMiddleware();
        Mail::fake();

        $ctx = $this->createHubAndCourse();
        $email = 'webhook-login-'.uniqid().'@test.local';

        $this->postJson('/api/webhooks/enrollment', [
            'name' => 'Aluno Webhook',
            'email' => $email,
            'course_id' => $ctx['course']->id,
            'platform' => 'kiwify',
            'event' => 'purchase_approved',
            'transaction_id' => 'tx-login-'.uniqid(),
            'send_access_email' => true,
        ], [
            'Authorization' => 'Bearer '.$this->plainToken,
        ])->assertOk();

        $user = User::query()->where('email', $email)->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check(EnrollmentWebhookService::DEFAULT_STUDENT_PASSWORD, $user->password));

        $this->post('/m/'.$ctx['hubSlug'].'/login', [
            'email' => $email,
            'password' => EnrollmentWebhookService::DEFAULT_STUDENT_PASSWORD,
        ])->assertRedirect('/m/'.$ctx['hubSlug']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_webhook_does_not_change_existing_student_password(): void
    {
        Mail::fake();

        $ctx = $this->createHubAndCourse();
        $customPassword = 'MinhaSenhaAntiga88';

        $aluno = User::factory()->create([
            'email' => 'existente-senha@test.local',
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
            'password' => Hash::make($customPassword),
        ]);
        $oldHash = $aluno->password;

        $this->postJson('/api/webhooks/enrollment', [
            'name' => 'Aluno Existente',
            'email' => 'existente-senha@test.local',
            'course_id' => $ctx['course']->id,
            'platform' => 'kiwify',
            'event' => 'purchase_approved',
            'transaction_id' => 'tx-pwd-'.uniqid(),
        ], [
            'Authorization' => 'Bearer '.$this->plainToken,
        ])->assertOk();

        $aluno->refresh();
        $this->assertSame($oldHash, $aluno->password);
        $this->assertTrue(Hash::check($customPassword, $aluno->password));
        $this->assertFalse(Hash::check(EnrollmentWebhookService::DEFAULT_STUDENT_PASSWORD, $aluno->password));
    }

    public function test_webhook_access_email_contains_initial_password_and_hub_link(): void
    {
        Mail::fake();

        $ctx = $this->createHubAndCourse();
        $email = 'email-cred-'.uniqid().'@test.local';

        $this->postJson('/api/webhooks/enrollment', [
            'name' => 'Aluno Email',
            'email' => $email,
            'course_id' => $ctx['course']->id,
            'platform' => 'kiwify',
            'event' => 'purchase_approved',
            'transaction_id' => 'tx-mail-'.uniqid(),
        ], [
            'Authorization' => 'Bearer '.$this->plainToken,
        ])->assertOk();

        Mail::assertSent(AccessGrantedMail::class, function (AccessGrantedMail $mail) use ($ctx, $email) {
            return str_contains($mail->htmlBody, $email)
                && str_contains($mail->htmlBody, 'Senha inicial:')
                && str_contains($mail->htmlBody, EnrollmentWebhookService::DEFAULT_STUDENT_PASSWORD)
                && str_contains($mail->htmlBody, '/m/'.$ctx['hubSlug'].'/login');
        });
    }

    public function test_student_without_course_can_access_hub_and_see_vitrine(): void
    {
        $this->withoutInstallMiddleware();

        $hubSlug = 'hub'.substr(uniqid('', true), -8);
        $hub = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $hubSlug,
            'is_member_hub' => true,
        ]);

        $offerCourse = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'oferta-'.substr(uniqid('', true), -8),
            'name' => 'Curso na vitrine',
            'member_hub_product_id' => $hub->id,
        ]);

        $section = MemberSection::create([
            'product_id' => $hub->id,
            'title' => 'Vitrine',
            'position' => 1,
            'section_type' => 'products',
        ]);

        MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $hub->id,
            'title' => 'Oferta curso',
            'position' => 1,
            'related_product_id' => $offerCourse->id,
            'access_type' => 'paid',
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);

        $this->actingAs($aluno)
            ->get('/m/'.$hubSlug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('is_member_hub', true)
                ->has('sections', 1)
                ->where('sections.0.section_type', 'products')
                ->where('sections.0.title', 'Vitrine')
            );
    }

    public function test_student_with_course_sees_my_courses_on_hub(): void
    {
        $this->withoutInstallMiddleware();

        $ctx = $this->createHubAndCourse();
        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $ctx['course']->users()->attach($aluno->id);

        $this->actingAs($aluno)
            ->get('/m/'.$ctx['hubSlug'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('is_member_hub', true)
                ->where('sections.0.section_type', 'my_courses')
                ->where('sections.0.title', 'Meus Cursos')
            );
    }

    public function test_student_from_other_tenant_is_blocked_on_login(): void
    {
        $this->withoutInstallMiddleware();

        $ctx = $this->createHubAndCourse();
        $aluno = User::factory()->create([
            'email' => 'outro-tenant@test.local',
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 2,
            'password' => Hash::make('password'),
        ]);

        $this->post('/m/'.$ctx['hubSlug'].'/login', [
            'email' => 'outro-tenant@test.local',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_module_thumbnail_is_resolved_to_public_storage_url(): void
    {
        $this->withoutInstallMiddleware();

        $courseSlug = 'cur'.substr(uniqid('', true), -8);
        $course = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $courseSlug,
        ]);

        $section = MemberSection::create([
            'product_id' => $course->id,
            'title' => 'Módulos',
            'position' => 1,
            'section_type' => 'courses',
        ]);

        MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $course->id,
            'title' => 'Módulo com capa',
            'position' => 1,
            'thumbnail' => 'member-area/modulo-capa.jpg',
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $course->users()->attach($aluno->id);

        $this->actingAs($aluno)
            ->get('/m/'.$courseSlug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sections.0.modules.0.thumbnail', '/storage/member-area/modulo-capa.jpg')
                ->where('sections.0.modules.0.thumbnail_url', '/storage/member-area/modulo-capa.jpg')
            );
    }

    public function test_module_content_page_resolves_module_thumbnails(): void
    {
        $this->withoutInstallMiddleware();

        $courseSlug = 'cur'.substr(uniqid('', true), -8);
        $course = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $courseSlug,
        ]);

        $section = MemberSection::create([
            'product_id' => $course->id,
            'title' => 'Módulos',
            'position' => 1,
            'section_type' => 'courses',
        ]);

        $module = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $course->id,
            'title' => 'Módulo A',
            'position' => 1,
            'thumbnail' => 'member-area/mod-a.jpg',
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $course->users()->attach($aluno->id);

        $this->actingAs($aluno)
            ->get('/m/'.$courseSlug.'/modulo/'.$module->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sections.0.modules.0.thumbnail', '/storage/member-area/mod-a.jpg')
            );
    }
}
