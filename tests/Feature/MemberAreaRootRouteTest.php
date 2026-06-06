<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDockerSetup;
use App\Http\Middleware\EnsureInstalled;
use App\Models\Product;
use App\Models\User;
use App\Services\EnrollmentWebhookService;
use App\Services\MemberAreaResolver;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MemberAreaRootRouteTest extends TestCase
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

    public function test_guest_root_redirects_to_official_hub_login_when_hub_exists(): void
    {
        $this->withoutInstallMiddleware();
        $this->createHub();

        $this->get('/')
            ->assertRedirect($this->hubLoginPath());
    }

    public function test_guest_login_on_main_domain_redirects_to_official_hub_login(): void
    {
        $this->withoutInstallMiddleware();
        $this->createHub();

        $this->get('/login')
            ->assertRedirect($this->hubLoginPath());
    }

    public function test_official_hub_login_renders_member_area_login_with_manifest(): void
    {
        $this->withoutInstallMiddleware();
        $this->createHub();

        $this->get($this->hubLoginPath())
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('MemberAreaApp/Login')
                ->where('slug', 'area-membros-1')
                ->where('product.manifest_url', fn ($url) => str_contains($url, '/m/area-membros-1/manifest.json')
                    || str_contains($url, 'manifest.json'))
            );
    }

    public function test_student_can_login_via_root_redirect_flow(): void
    {
        $this->withoutInstallMiddleware();

        $hubSlug = 'area-membros-1';
        $this->createHub($hubSlug);

        $course = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'curso-hub-'.substr(uniqid('', true), -6),
            'member_hub_product_id' => Product::query()->where('checkout_slug', $hubSlug)->value('id'),
        ]);

        $email = 'root-flow-'.uniqid().'@test.local';
        $aluno = User::factory()->create([
            'email' => $email,
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
            'password' => Hash::make(EnrollmentWebhookService::DEFAULT_STUDENT_PASSWORD),
        ]);
        $course->users()->attach($aluno->id);

        $this->get('/login')->assertRedirect($this->hubLoginPath($hubSlug));

        $this->post($this->hubLoginPath($hubSlug), [
            'email' => $email,
            'password' => EnrollmentWebhookService::DEFAULT_STUDENT_PASSWORD,
        ])->assertRedirect('/m/'.$hubSlug);

        $this->assertAuthenticatedAs($aluno);
    }

    public function test_admin_login_uses_dedicated_route_when_hub_exists(): void
    {
        $this->withoutInstallMiddleware();
        $this->createHub();

        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
            'email' => 'admin@test.local',
        ]);

        $this->get('/admin/login')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/Login'));

        $this->post('/admin/login', [
            'email' => 'admin@test.local',
            'password' => 'password',
        ])->assertRedirect('/dashboard');
    }

    public function test_logged_in_student_root_redirects_to_official_hub_home_when_hub_exists(): void
    {
        $this->withoutInstallMiddleware();

        $hubSlug = 'area-membros-1';
        $hub = $this->createHub($hubSlug);
        $aluno = User::factory()->create(['role' => User::ROLE_ALUNO, 'tenant_id' => 1]);
        $hub->users()->attach($aluno->id);

        $this->actingAs($aluno)
            ->get('/')
            ->assertRedirect('/m/'.$hubSlug);
    }

    public function test_logged_in_admin_root_redirects_to_dashboard_when_hub_exists(): void
    {
        $this->withoutInstallMiddleware();
        $this->createHub();

        $admin = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $this->actingAs($admin)
            ->get('/')
            ->assertRedirect('/dashboard');
    }

    public function test_official_member_area_login_path_returns_hub_login_url(): void
    {
        $hub = $this->createHub('hub-validacao');

        $path = app(MemberAreaResolver::class)->officialMemberAreaLoginPath($hub->tenant_id);
        $url = app(MemberAreaResolver::class)->officialMemberAreaLoginUrl($hub->tenant_id);

        $this->assertSame('/m/hub-validacao/login', $path);
        $this->assertStringContainsString('/m/hub-validacao/login', $url);
    }

    public function test_guest_dashboard_redirects_to_admin_login_when_hub_exists(): void
    {
        $this->withoutInstallMiddleware();
        $this->createHub();

        $this->get('/dashboard')
            ->assertRedirect('/admin/login');
    }

    public function test_member_logout_on_main_domain_returns_to_official_hub_login(): void
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

    public function test_member_area_login_path_on_main_host_with_hub_uses_official_path(): void
    {
        $hub = $this->createHub('area-membros-1');

        $request = \Illuminate\Http\Request::create(rtrim((string) config('app.url'), '/').'/login', 'GET');
        $path = app(MemberAreaResolver::class)->memberAreaLoginPath($request, $hub);

        $this->assertSame('/m/area-membros-1/login', $path);
    }

    public function test_guest_root_without_hub_still_redirects_to_platform_login(): void
    {
        $this->withoutInstallMiddleware();

        $this->get('/')
            ->assertRedirect('/login');
    }

    public function test_admin_login_without_hub_still_works_on_login_route(): void
    {
        $this->withoutInstallMiddleware();

        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
            'email' => 'admin@test.local',
        ]);

        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/Login'));

        $this->post('/login', [
            'email' => 'admin@test.local',
            'password' => 'password',
        ])->assertRedirect('/dashboard');
    }
}
