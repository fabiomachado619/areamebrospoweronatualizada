<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDockerSetup;
use App\Http\Middleware\EnsureInstalled;
use App\Models\Product;
use App\Models\TeamRole;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

class MemberAreaUnifiedLoginTest extends TestCase
{
    private function withoutInstallMiddleware(): void
    {
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureDockerSetup::class,
            ValidateCsrfToken::class,
        ]);
    }

    private function createHub(string $slug = 'area-membros-1'): Product
    {
        return $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $slug,
            'name' => 'Área de Membros',
            'is_member_hub' => true,
        ]);
    }

    private function hubLoginPath(string $slug = 'area-membros-1'): string
    {
        return '/m/'.$slug.'/login';
    }

    public function test_student_login_via_member_area_goes_to_hub_home(): void
    {
        $this->withoutInstallMiddleware();

        $hubSlug = 'area-membros-1';
        $hub = $this->createHub($hubSlug);
        $aluno = User::factory()->create([
            'email' => 'aluno-unified@test.local',
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $hub->users()->attach($aluno->id);

        $this->post($this->hubLoginPath($hubSlug), [
            'email' => 'aluno-unified@test.local',
            'password' => 'password',
        ])->assertRedirect('/m/'.$hubSlug);

        $this->assertAuthenticatedAs($aluno);
    }

    public function test_admin_login_via_member_area_goes_to_dashboard(): void
    {
        $this->withoutInstallMiddleware();

        $hubSlug = 'area-membros-1';
        $this->createHub($hubSlug);

        User::factory()->create([
            'email' => 'admin-unified@test.local',
            'role' => User::ROLE_ADMIN,
            'tenant_id' => 1,
        ]);

        $this->post($this->hubLoginPath($hubSlug), [
            'email' => 'admin-unified@test.local',
            'password' => 'password',
        ])->assertRedirect('/dashboard');
    }

    public function test_infoprodutor_login_via_member_area_goes_to_dashboard(): void
    {
        $this->withoutInstallMiddleware();

        $hubSlug = 'area-membros-1';
        $this->createHub($hubSlug);

        User::factory()->create([
            'email' => 'info-unified@test.local',
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $this->post($this->hubLoginPath($hubSlug), [
            'email' => 'info-unified@test.local',
            'password' => 'password',
        ])->assertRedirect('/dashboard');
    }

    public function test_team_login_via_member_area_goes_to_dashboard(): void
    {
        $this->withoutInstallMiddleware();

        $hubSlug = 'area-membros-1';
        $this->createHub($hubSlug);

        User::factory()->create([
            'email' => 'team-unified@test.local',
            'role' => User::ROLE_TEAM,
            'tenant_id' => 1,
        ]);

        $this->post($this->hubLoginPath($hubSlug), [
            'email' => 'team-unified@test.local',
            'password' => 'password',
        ])->assertRedirect('/dashboard');
    }

    public function test_same_tenant_student_without_course_can_login_to_hub(): void
    {
        $this->withoutInstallMiddleware();

        $hubSlug = 'area-membros-1';
        $this->createHub($hubSlug);

        $aluno = User::factory()->create([
            'email' => 'aluno-vitrine@test.local',
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);

        $this->post($this->hubLoginPath($hubSlug), [
            'email' => 'aluno-vitrine@test.local',
            'password' => 'password',
        ])->assertRedirect('/m/'.$hubSlug);

        $this->assertAuthenticatedAs($aluno);
    }

    public function test_same_tenant_student_with_course_can_login_to_hub(): void
    {
        $this->withoutInstallMiddleware();

        $hubSlug = 'area-membros-1';
        $hub = $this->createHub($hubSlug);
        $course = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'curso-'.substr(uniqid('', true), -6),
            'member_hub_product_id' => $hub->id,
        ]);

        $aluno = User::factory()->create([
            'email' => 'aluno-curso@test.local',
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $course->users()->attach($aluno->id);

        $this->post($this->hubLoginPath($hubSlug), [
            'email' => 'aluno-curso@test.local',
            'password' => 'password',
        ])->assertRedirect('/m/'.$hubSlug);

        $this->assertAuthenticatedAs($aluno);
    }

    public function test_wrong_password_shows_invalid_credentials_not_access_error(): void
    {
        $this->withoutInstallMiddleware();

        $hubSlug = 'area-membros-1';
        $this->createHub($hubSlug);

        User::factory()->create([
            'email' => 'aluno-senha@test.local',
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);

        $response = $this->post($this->hubLoginPath($hubSlug), [
            'email' => 'aluno-senha@test.local',
            'password' => 'senha-errada',
        ]);

        $response->assertSessionHasErrors('email');
        $response->assertSessionHasErrors(['email' => 'Credenciais inválidas.']);
        $this->assertGuest();
    }

    public function test_cross_tenant_student_gets_access_denied_message(): void
    {
        $this->withoutInstallMiddleware();

        $hubSlug = 'area-membros-1';
        $this->createHub($hubSlug);

        User::factory()->create([
            'email' => 'aluno-outro@test.local',
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 2,
            'password' => bcrypt('password'),
        ]);

        $response = $this->post($this->hubLoginPath($hubSlug), [
            'email' => 'aluno-outro@test.local',
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors(['email' => 'Você não tem acesso a esta área.']);
        $this->assertGuest();
    }

    public function test_cross_tenant_admin_login_via_member_area_still_goes_to_dashboard(): void
    {
        $this->withoutInstallMiddleware();

        $hubSlug = 'area-membros-1';
        $this->createHub($hubSlug);

        User::factory()->create([
            'email' => 'admin-outro-tenant@test.local',
            'role' => User::ROLE_ADMIN,
            'tenant_id' => 2,
        ]);

        $this->post($this->hubLoginPath($hubSlug), [
            'email' => 'admin-outro-tenant@test.local',
            'password' => 'password',
        ])->assertRedirect('/dashboard');
    }

    public function test_team_without_dashboard_permission_goes_to_first_allowed_route(): void
    {
        $this->withoutInstallMiddleware();

        $this->createHub('hub-validacao');

        $role = TeamRole::create([
            'tenant_id' => 19,
            'name' => 'Professor',
            'permissions' => [
                'dashboard.view' => false,
                'vendas.view' => false,
                'produtos.view' => true,
                'relatorios.view' => false,
                'integracoes.view' => false,
                'email_marketing.view' => false,
                'api_pagamentos.view' => false,
                'configuracoes.view' => false,
                'equipe.manage' => false,
                'reembolsos.view' => false,
                'reembolsos.manage' => false,
                'financeiro.view' => false,
                'financeiro.manage' => false,
            ],
        ]);

        $team = User::factory()->create([
            'email' => 'professor-sem-dash@test.local',
            'role' => User::ROLE_TEAM,
            'tenant_id' => 19,
            'team_role_id' => $role->id,
            'password' => bcrypt('12345678'),
        ]);

        $this->post('/m/hub-validacao/login', [
            'email' => 'professor-sem-dash@test.local',
            'password' => '12345678',
        ])->assertRedirect('/produtos');

        $this->assertAuthenticatedAs($team);
    }

    public function test_team_login_at_wrong_hub_still_goes_to_dashboard(): void
    {
        $this->withoutInstallMiddleware();

        $this->createHub('hub-validacao');

        $role = TeamRole::create([
            'tenant_id' => 19,
            'name' => 'Equipe dashboard',
            'permissions' => [
                'dashboard.view' => true,
                'vendas.view' => false,
                'produtos.view' => false,
                'relatorios.view' => false,
                'integracoes.view' => false,
                'email_marketing.view' => false,
                'api_pagamentos.view' => false,
                'configuracoes.view' => false,
                'equipe.manage' => false,
                'reembolsos.view' => false,
                'reembolsos.manage' => false,
                'financeiro.view' => false,
                'financeiro.manage' => false,
            ],
        ]);

        $team = User::factory()->create([
            'email' => 'professor@test.local',
            'role' => User::ROLE_TEAM,
            'tenant_id' => 19,
            'team_role_id' => $role->id,
            'password' => bcrypt('12345678'),
        ]);

        $this->post('/m/hub-validacao/login', [
            'email' => 'professor@test.local',
            'password' => '12345678',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($team);
    }

    public function test_team_login_with_mixed_case_email_goes_to_dashboard(): void
    {
        $this->withoutInstallMiddleware();

        $this->createHub('hub-validacao');

        $team = User::factory()->create([
            'email' => 'PROFESSOR.CASE@test.local',
            'role' => User::ROLE_TEAM,
            'tenant_id' => 1,
        ]);

        $this->post('/m/hub-validacao/login', [
            'email' => 'professor.case@test.local',
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($team);
    }

    public function test_admin_login_at_member_area_goes_to_dashboard(): void
    {
        $this->withoutInstallMiddleware();

        $this->createHub('hub-validacao');

        $admin = User::factory()->create([
            'email' => 'admin-area@test.local',
            'role' => User::ROLE_ADMIN,
            'tenant_id' => 19,
        ]);

        $this->post('/m/hub-validacao/login', [
            'email' => 'admin-area@test.local',
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($admin);
    }

    public function test_student_logging_wrong_hub_redirects_to_their_tenant_hub(): void
    {
        $this->withoutInstallMiddleware();

        $this->createHub('hub-validacao');

        $hubT2 = $this->createTestProduct([
            'tenant_id' => 2,
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'area-membros-2',
            'name' => 'Área tenant 2',
            'is_member_hub' => true,
        ]);

        $course = $this->createTestProduct([
            'tenant_id' => 2,
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'curso-t2-'.substr(uniqid('', true), -6),
            'member_hub_product_id' => $hubT2->id,
        ]);

        $aluno = User::factory()->create([
            'email' => 'aluno-hub-errado@test.local',
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 2,
        ]);
        $course->users()->attach($aluno->id);

        $this->post('/m/hub-validacao/login', [
            'email' => 'aluno-hub-errado@test.local',
            'password' => 'password',
        ])->assertRedirect('/m/area-membros-2');

        $this->assertAuthenticatedAs($aluno);
    }

    public function test_student_without_access_gets_error_on_member_area_login(): void
    {
        $this->withoutInstallMiddleware();

        $hubSlug = 'area-membros-1';
        $this->createHub($hubSlug);

        User::factory()->create([
            'email' => 'aluno-sem-acesso@test.local',
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 2,
        ]);

        $this->post($this->hubLoginPath($hubSlug), [
            'email' => 'aluno-sem-acesso@test.local',
            'password' => 'password',
        ])->assertSessionHasErrors(['email' => 'Você não tem acesso a esta área.']);

        $this->assertGuest();
    }

    public function test_student_cannot_access_dashboard(): void
    {
        $this->withoutInstallMiddleware();

        $this->createHub();
        $aluno = User::factory()->create(['role' => User::ROLE_ALUNO, 'tenant_id' => 1]);

        $this->actingAs($aluno)
            ->get('/dashboard')
            ->assertForbidden();
    }

    public function test_admin_direct_login_route_goes_to_dashboard(): void
    {
        $this->withoutInstallMiddleware();

        $this->createHub();

        User::factory()->create([
            'email' => 'admin-direct@test.local',
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $this->get('/admin/login')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/Login'));

        $this->post('/admin/login', [
            'email' => 'admin-direct@test.local',
            'password' => 'password',
        ])->assertRedirect('/dashboard');
    }

    public function test_logged_in_admin_visiting_admin_login_redirects_to_dashboard(): void
    {
        $this->withoutInstallMiddleware();

        $this->createHub();

        $admin = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $this->actingAs($admin)
            ->get('/admin/login')
            ->assertRedirect('/dashboard');
    }

    public function test_root_login_redirects_to_member_area_login_when_hub_exists(): void
    {
        $this->withoutInstallMiddleware();

        $hubSlug = 'area-membros-1';
        $this->createHub($hubSlug);

        $this->get('/login')
            ->assertRedirect($this->hubLoginPath($hubSlug));
    }

    public function test_student_logout_returns_to_member_area_login(): void
    {
        $this->withoutInstallMiddleware();

        $hubSlug = 'area-membros-1';
        $hub = $this->createHub($hubSlug);
        $aluno = User::factory()->create(['role' => User::ROLE_ALUNO, 'tenant_id' => 1]);
        $hub->users()->attach($aluno->id);

        $this->actingAs($aluno)
            ->post('/logout?redirect='.urlencode($this->hubLoginPath($hubSlug)))
            ->assertRedirect($this->hubLoginPath($hubSlug));

        $this->assertGuest();
    }

    public function test_admin_logout_from_dashboard_is_not_blocked(): void
    {
        $this->withoutInstallMiddleware();

        $this->createHub();

        $admin = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $this->actingAs($admin)
            ->post('/logout')
            ->assertRedirect('/');

        $this->assertGuest();
    }
}
