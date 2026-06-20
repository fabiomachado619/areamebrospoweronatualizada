<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDockerSetup;
use App\Http\Middleware\EnsureInstalled;
use App\Mail\AccessGrantedMail;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\AccessEmailService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MemberAreaAccessEmailTest extends TestCase
{
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
            'name' => 'Área de Membros',
            'is_member_hub' => true,
        ]);

        $course = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $courseSlug,
            'name' => 'Curso UPA',
            'member_hub_product_id' => $hub->id,
        ]);

        return compact('hub', 'course', 'hubSlug', 'courseSlug');
    }

    public function test_resolve_access_url_uses_hub_when_configured(): void
    {
        $ctx = $this->createHubAndCourse();
        $user = User::factory()->create(['role' => User::ROLE_ALUNO, 'tenant_id' => 1]);
        $ctx['course']->users()->attach($user->id);

        $url = app(AccessEmailService::class)->resolveAccessUrl($user, $ctx['course']);

        $this->assertStringContainsString('/m/'.$ctx['hubSlug'].'/login', $url);
        $this->assertStringNotContainsString('/m/'.$ctx['courseSlug'].'/access', $url);
    }

    public function test_resolve_access_url_falls_back_to_course_without_hub(): void
    {
        $courseSlug = 'solo'.substr(uniqid('', true), -8);
        $course = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $courseSlug,
        ]);
        $user = User::factory()->create(['role' => User::ROLE_ALUNO, 'tenant_id' => 1]);
        $course->users()->attach($user->id);

        $url = app(AccessEmailService::class)->resolveAccessUrl($user, $course);

        $this->assertStringContainsString('/m/'.$courseSlug, $url);
    }

    public function test_send_for_order_uses_hub_link_in_email(): void
    {
        Mail::fake();

        $ctx = $this->createHubAndCourse();
        $user = User::factory()->create([
            'email' => 'pedido-hub@test.local',
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
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
        ]);
        $order->load(['product', 'user']);

        $this->assertTrue(app(AccessEmailService::class)->sendForOrder($order, true));

        Mail::assertSent(AccessGrantedMail::class, function (AccessGrantedMail $mail) use ($ctx) {
            return str_contains($mail->htmlBody, '/m/'.$ctx['hubSlug'].'/login')
                && str_contains($mail->htmlBody, 'Curso UPA');
        });
    }

    public function test_send_for_user_product_uses_hub_link_for_manual_grant(): void
    {
        Mail::fake();

        $ctx = $this->createHubAndCourse();
        $user = User::factory()->create([
            'email' => 'manual-hub@test.local',
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);

        $this->assertTrue(app(AccessEmailService::class)->sendForUserProduct($user, $ctx['course']));

        Mail::assertSent(AccessGrantedMail::class, function (AccessGrantedMail $mail) use ($ctx) {
            return str_contains($mail->htmlBody, '/m/'.$ctx['hubSlug'].'/login');
        });
    }

    public function test_send_for_enrollment_access_uses_hub_link(): void
    {
        Mail::fake();

        $ctx = $this->createHubAndCourse();
        $user = User::factory()->create([
            'email' => 'webhook-hub@test.local',
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);

        $this->assertTrue(app(AccessEmailService::class)->sendForEnrollmentAccess(
            $user,
            $ctx['course'],
            'SenhaNova123'
        ));

        Mail::assertSent(AccessGrantedMail::class, function (AccessGrantedMail $mail) use ($ctx) {
            return str_contains($mail->htmlBody, '/m/'.$ctx['hubSlug'].'/login')
                && str_contains($mail->htmlBody, 'Acessar Área de Membros')
                && str_contains($mail->htmlBody, 'SenhaNova123')
                && str_contains($mail->htmlBody, 'Senha inicial:');
        });
    }

    public function test_send_for_enrollment_access_notifies_existing_student_for_additional_course(): void
    {
        Mail::fake();

        $ctx = $this->createHubAndCourse();
        $otherCourseSlug = 'out'.substr(uniqid('', true), -8);
        $otherCourse = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $otherCourseSlug,
            'name' => 'Curso Anterior',
            'member_hub_product_id' => $ctx['hub']->id,
        ]);

        $user = User::factory()->create([
            'email' => 'existing-webhook@test.local',
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $otherCourse->users()->attach($user->id);

        $this->assertTrue(app(AccessEmailService::class)->sendForEnrollmentAccess(
            $user,
            $ctx['course'],
            null,
        ));

        Mail::assertSent(AccessGrantedMail::class, function (AccessGrantedMail $mail) use ($ctx) {
            return str_contains($mail->subjectLine, 'Novo treinamento liberado')
                && str_contains($mail->subjectLine, $ctx['course']->name)
                && str_contains($mail->htmlBody, '/m/'.$ctx['hubSlug'].'/login')
                && str_contains($mail->htmlBody, $ctx['course']->name)
                && ! str_contains($mail->htmlBody, 'Senha inicial:');
        });
    }

    public function test_send_for_user_product_includes_password_when_provided(): void
    {
        Mail::fake();

        $ctx = $this->createHubAndCourse();
        $user = User::factory()->create([
            'email' => 'manual-pwd@test.local',
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);

        $this->assertTrue(app(AccessEmailService::class)->sendForUserProduct($user, $ctx['course'], 'SenhaManual77'));

        Mail::assertSent(AccessGrantedMail::class, function (AccessGrantedMail $mail) use ($ctx) {
            return str_contains($mail->htmlBody, '/m/'.$ctx['hubSlug'].'/login')
                && str_contains($mail->htmlBody, 'SenhaManual77')
                && str_contains($mail->htmlBody, 'Senha inicial:');
        });
    }

    public function test_standalone_course_email_keeps_course_link(): void
    {
        Mail::fake();

        $courseSlug = 'solo'.substr(uniqid('', true), -8);
        $course = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $courseSlug,
            'name' => 'Curso Solo',
        ]);
        $user = User::factory()->create([
            'email' => 'solo@test.local',
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);

        $this->assertTrue(app(AccessEmailService::class)->sendForUserProduct($user, $course));

        Mail::assertSent(AccessGrantedMail::class, function (AccessGrantedMail $mail) use ($courseSlug) {
            return str_contains($mail->htmlBody, '/m/'.$courseSlug);
        });
    }

    public function test_access_email_official_login_url_renders_member_area_login(): void
    {
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureDockerSetup::class,
        ]);

        $ctx = $this->createHubAndCourse();
        $user = User::factory()->create(['role' => User::ROLE_ALUNO, 'tenant_id' => 1]);
        $ctx['course']->users()->attach($user->id);

        $loginUrl = app(AccessEmailService::class)->resolveAccessUrl($user, $ctx['course']);
        $path = parse_url($loginUrl, PHP_URL_PATH);

        $this->assertSame('/m/'.$ctx['hubSlug'].'/login', $path);

        $this->get($path)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('MemberAreaApp/Login'));
    }

    public function test_magic_link_from_course_slug_redirects_to_hub(): void
    {
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureDockerSetup::class,
        ]);

        $ctx = $this->createHubAndCourse();
        $user = User::factory()->create(['role' => User::ROLE_ALUNO, 'tenant_id' => 1]);
        $ctx['course']->users()->attach($user->id);

        $legacyUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'member-area.magic-access',
            now()->addDay(),
            [
                'slug' => $ctx['courseSlug'],
                'u' => $user->id,
                'p' => $ctx['hub']->id,
            ]
        );

        $response = $this->get($legacyUrl);

        $response->assertRedirect('/m/'.$ctx['hubSlug']);
        $this->assertAuthenticatedAs($user);
    }
}
