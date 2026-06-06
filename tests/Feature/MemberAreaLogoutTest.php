<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDockerSetup;
use App\Http\Middleware\EnsureInstalled;
use App\Models\MemberAreaDomain;
use App\Models\Product;
use App\Models\User;
use App\Services\MemberAreaResolver;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Str;
use Tests\TestCase;

class MemberAreaLogoutTest extends TestCase
{
    private function withoutInstallMiddleware(): void
    {
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureDockerSetup::class,
            ValidateCsrfToken::class,
        ]);
    }

    private function createMemberAreaProduct(string $slug, array $extra = []): Product
    {
        return $this->createTestProduct(array_merge([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $slug,
            'name' => 'Área Teste',
        ], $extra));
    }

    public function test_student_logout_from_path_member_area_redirects_to_member_login(): void
    {
        $this->withoutInstallMiddleware();

        $hubSlug = 'area-membros-1';
        $hub = $this->createMemberAreaProduct($hubSlug, ['is_member_hub' => true]);
        $aluno = User::factory()->create(['role' => User::ROLE_ALUNO, 'tenant_id' => 1]);
        $hub->users()->attach($aluno->id);

        $loginPath = '/m/'.$hubSlug.'/login';

        $this->actingAs($aluno)
            ->post('/logout?redirect='.urlencode($loginPath))
            ->assertRedirect($loginPath);

        $this->assertGuest();
    }

    public function test_student_logout_from_path_uses_referer_when_redirect_invalid(): void
    {
        $this->withoutInstallMiddleware();

        $hubSlug = 'hub-test-99';
        $hub = $this->createMemberAreaProduct($hubSlug, ['is_member_hub' => true]);
        $aluno = User::factory()->create(['role' => User::ROLE_ALUNO, 'tenant_id' => 1]);
        $hub->users()->attach($aluno->id);

        $this->actingAs($aluno)
            ->withHeaders(['Referer' => url('/m/'.$hubSlug.'/modulo/1')])
            ->post('/logout?redirect=/invalid')
            ->assertRedirect('/m/'.$hubSlug.'/login');

        $this->assertGuest();
    }

    public function test_student_logout_on_custom_domain_redirects_to_login_on_same_host(): void
    {
        $this->withoutInstallMiddleware();

        $slug = strtolower('ab'.Str::random(4));
        $product = $this->createMemberAreaProduct($slug);
        MemberAreaDomain::create([
            'product_id' => $product->id,
            'type' => MemberAreaDomain::TYPE_CUSTOM,
            'value' => 'curso.test',
        ]);

        $aluno = User::factory()->create(['role' => User::ROLE_ALUNO, 'tenant_id' => 1]);
        $product->users()->attach($aluno->id);

        $this->actingAs($aluno)
            ->post('http://curso.test/logout?redirect='.urlencode('/login'))
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_custom_domain_root_guest_redirects_to_member_login(): void
    {
        $this->withoutInstallMiddleware();

        $slug = strtolower('ab'.Str::random(4));
        $product = $this->createMemberAreaProduct($slug);
        MemberAreaDomain::create([
            'product_id' => $product->id,
            'type' => MemberAreaDomain::TYPE_CUSTOM,
            'value' => 'curso.test',
        ]);

        $this->get('http://curso.test/')
            ->assertRedirect('/login');
    }

    public function test_custom_domain_root_logged_student_sees_member_area_home(): void
    {
        $this->withoutInstallMiddleware();

        $slug = strtolower('ab'.Str::random(4));
        $product = $this->createMemberAreaProduct($slug);
        MemberAreaDomain::create([
            'product_id' => $product->id,
            'type' => MemberAreaDomain::TYPE_CUSTOM,
            'value' => 'curso.test',
        ]);

        $aluno = User::factory()->create(['role' => User::ROLE_ALUNO, 'tenant_id' => 1]);
        $product->users()->attach($aluno->id);

        $this->actingAs($aluno)
            ->get('http://curso.test/')
            ->assertOk();
    }

    public function test_admin_logout_on_main_domain_is_not_affected(): void
    {
        $this->withoutInstallMiddleware();

        $admin = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);

        $this->actingAs($admin)
            ->post('/logout')
            ->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_admin_login_on_main_domain_still_works(): void
    {
        $this->withoutInstallMiddleware();

        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
            'email' => 'admin@test.local',
        ]);

        $this->post('/login', [
            'email' => 'admin@test.local',
            'password' => 'password',
        ])->assertRedirect('/dashboard');
    }

    public function test_member_area_login_path_resolver_supports_hyphenated_slug(): void
    {
        $hubSlug = 'area-membros-1';
        $hub = $this->createMemberAreaProduct($hubSlug, ['is_member_hub' => true]);

        $request = \Illuminate\Http\Request::create('/m/'.$hubSlug.'/login', 'GET');
        $path = app(MemberAreaResolver::class)->memberAreaLoginPath($request, $hub);

        $this->assertSame('/m/'.$hubSlug.'/login', $path);
    }
}
