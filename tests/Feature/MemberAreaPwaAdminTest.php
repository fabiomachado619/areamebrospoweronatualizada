<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDockerSetup;
use App\Http\Middleware\EnsureInstalled;
use App\Models\Product;
use App\Models\User;
use App\Services\AccessEmailService;
use App\Services\MemberHubService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class MemberAreaPwaAdminTest extends TestCase
{
    private function withoutInstallMiddleware(): void
    {
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureDockerSetup::class,
            ValidateCsrfToken::class,
        ]);
    }

    private function infoprodutor(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);
    }

    private function ensureHubWithCourse(): array
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
            'name' => 'Curso Teste',
            'member_hub_product_id' => $hub->id,
        ]);

        return compact('hub', 'course', 'hubSlug', 'courseSlug');
    }

    public function test_pwa_tab_loads_with_settings(): void
    {
        $this->withoutInstallMiddleware();

        $owner = $this->infoprodutor();
        $ctx = $this->ensureHubWithCourse();

        $ctx['hub']->update([
            'member_area_config' => array_replace_recursive(
                Product::defaultMemberAreaConfig(),
                [
                    'pwa' => [
                        'name' => 'Power On Treinamentos',
                        'short_name' => 'Power On',
                        'theme_color' => '#ff5500',
                    ],
                    'theme' => ['background' => '#111111'],
                    'logos' => ['favicon' => 'https://cdn.test/icon.png'],
                ]
            ),
        ]);

        $response = $this->actingAs($owner)->get('/area-membros-admin?tab=pwa');

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tab', 'pwa')
                ->where('pwa_settings.name', 'Power On Treinamentos')
                ->where('pwa_settings.short_name', 'Power On')
                ->where('pwa_settings.theme_color', '#ff5500')
                ->where('pwa_settings.background_color', '#111111')
                ->where('pwa_settings.favicon', 'https://cdn.test/icon.png')
                ->has('member_area_url')
                ->has('manifest_url')
                ->has('pwa_status')
            );
    }

    public function test_admin_can_save_pwa_settings_on_hub(): void
    {
        $this->withoutInstallMiddleware();

        $owner = $this->infoprodutor();
        $this->ensureHubWithCourse();

        $response = $this->actingAs($owner)->postJson('/area-membros-admin/pwa', [
            'name' => 'Meu App',
            'short_name' => 'MeuApp',
            'theme_color' => '#123456',
            'background_color' => '#abcdef',
            'favicon' => 'https://cdn.test/saved-icon.png',
            'push_enabled' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('pwa_settings.name', 'Meu App')
            ->assertJsonPath('pwa_settings.short_name', 'MeuApp')
            ->assertJsonPath('pwa_settings.theme_color', '#123456')
            ->assertJsonPath('pwa_settings.background_color', '#abcdef')
            ->assertJsonPath('pwa_settings.favicon', 'https://cdn.test/saved-icon.png');

        $hub = app(MemberHubService::class)->hubForTenant(1);
        $this->assertNotNull($hub);
        $config = $hub->fresh()->member_area_config;
        $this->assertSame('Meu App', $config['pwa']['name']);
        $this->assertSame('MeuApp', $config['pwa']['short_name']);
        $this->assertSame('#123456', $config['pwa']['theme_color']);
        $this->assertSame('#abcdef', $config['theme']['background']);
        $this->assertSame('https://cdn.test/saved-icon.png', $config['logos']['favicon']);
    }

    public function test_course_manifest_inherits_hub_pwa_settings(): void
    {
        $this->withoutInstallMiddleware();

        $ctx = $this->ensureHubWithCourse();

        $ctx['hub']->update([
            'member_area_config' => array_replace_recursive(
                Product::defaultMemberAreaConfig(),
                [
                    'pwa' => [
                        'name' => 'App Herdado do Hub',
                        'short_name' => 'HubApp',
                        'theme_color' => '#112233',
                    ],
                    'theme' => ['background' => '#445566'],
                    'logos' => ['favicon' => 'https://cdn.test/hub-icon.png'],
                ]
            ),
        ]);

        $this->get('/m/'.$ctx['hubSlug'].'/manifest.json')
            ->assertOk()
            ->assertJson([
                'name' => 'App Herdado do Hub',
                'short_name' => 'HubApp',
            ]);

        $courseManifest = $this->get('/m/'.$ctx['courseSlug'].'/manifest.json');

        $courseManifest->assertOk()
            ->assertJson([
                'name' => 'App Herdado do Hub',
                'short_name' => 'HubApp',
                'theme_color' => '#112233',
                'background_color' => '#445566',
            ]);

        $icons = $courseManifest->json('icons');
        $this->assertNotEmpty($icons);
        $this->assertStringContainsString('hub-icon.png', $icons[0]['src']);
    }

    public function test_manifest_has_no_store_cache_headers(): void
    {
        $this->withoutInstallMiddleware();

        $ctx = $this->ensureHubWithCourse();

        $response = $this->get('/m/'.$ctx['hubSlug'].'/manifest.json');

        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_manifest_reflects_hub_pwa_settings(): void
    {
        $this->withoutInstallMiddleware();

        $ctx = $this->ensureHubWithCourse();

        $ctx['hub']->update([
            'member_area_config' => array_replace_recursive(
                Product::defaultMemberAreaConfig(),
                [
                    'pwa' => [
                        'name' => 'App Manifest Test',
                        'short_name' => 'Manifest',
                        'theme_color' => '#aabbcc',
                    ],
                    'theme' => ['background' => '#010203'],
                    'logos' => ['favicon' => 'https://cdn.test/manifest-icon.png'],
                ]
            ),
        ]);

        $response = $this->get('/m/'.$ctx['hubSlug'].'/manifest.json');

        $response->assertOk()
            ->assertJson([
                'name' => 'App Manifest Test',
                'short_name' => 'Manifest',
                'theme_color' => '#aabbcc',
                'background_color' => '#010203',
            ]);

        $icons = $response->json('icons');
        $this->assertNotEmpty($icons);
        $this->assertStringContainsString('manifest-icon.png', $icons[0]['src']);
    }

    public function test_member_area_url_is_available_on_pwa_tab(): void
    {
        $this->withoutInstallMiddleware();

        $owner = $this->infoprodutor();
        $ctx = $this->ensureHubWithCourse();

        $response = $this->actingAs($owner)->get('/area-membros-admin?tab=pwa');

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('member_area_url', fn ($url) => str_contains($url, '/m/'.$ctx['hubSlug']))
                ->where('manifest_url', fn ($url) => str_contains($url, '/m/'.$ctx['hubSlug'].'/manifest.json')
            ));
    }

    public function test_access_email_still_points_to_hub_after_pwa_save(): void
    {
        $this->withoutInstallMiddleware();

        $owner = $this->infoprodutor();
        $ctx = $this->ensureHubWithCourse();
        $user = User::factory()->create(['role' => User::ROLE_ALUNO, 'tenant_id' => 1]);
        $ctx['course']->users()->attach($user->id);

        $this->actingAs($owner)->postJson('/area-membros-admin/pwa', [
            'name' => 'Email Test App',
            'short_name' => 'Email',
            'theme_color' => '#0ea5e9',
            'background_color' => '#18181b',
        ])->assertOk();

        $url = app(AccessEmailService::class)->resolveAccessUrl($user, $ctx['course']);

        $this->assertStringContainsString('/m/'.$ctx['hubSlug'].'/login', $url);
        $this->assertStringNotContainsString('/m/'.$ctx['courseSlug'], parse_url($url, PHP_URL_PATH) ?? $url);
    }

    public function test_admin_can_upload_pwa_icon(): void
    {
        $this->withoutInstallMiddleware();

        $owner = $this->infoprodutor();
        $ctx = $this->ensureHubWithCourse();

        $file = UploadedFile::fake()->image('icon.png', 512, 512);

        $response = $this->actingAs($owner)->postJson('/area-membros-admin/pwa/upload-icon', [
            'file' => $file,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['url', 'pwa_settings' => ['favicon']]);

        $hub = $ctx['hub']->fresh();
        $this->assertNotEmpty($hub->member_area_config['logos']['favicon'] ?? null);
    }

    public function test_no_migration_required_uses_member_area_config(): void
    {
        $this->withoutInstallMiddleware();

        $owner = $this->infoprodutor();
        $this->ensureHubWithCourse();

        $this->actingAs($owner)->postJson('/area-membros-admin/pwa', [
            'name' => 'Sem Migration',
            'short_name' => 'OK',
        ])->assertOk();

        $hub = app(MemberHubService::class)->hubForTenant(1);
        $this->assertIsArray($hub->member_area_config);
        $this->assertSame('Sem Migration', $hub->member_area_config['pwa']['name']);
    }
}
