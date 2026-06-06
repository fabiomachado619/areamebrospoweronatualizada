<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDockerSetup;
use App\Http\Middleware\EnsureInstalled;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use App\Services\AccessEmailService;
use App\Services\MemberHubService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MemberAreaAdminTest extends TestCase
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

    public function test_infoprodutor_can_access_member_area_admin_page(): void
    {
        $this->withoutInstallMiddleware();

        $owner = $this->infoprodutor();

        $response = $this->actingAs($owner)->get(route('member-area-admin.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('MemberAreaAdmin/Index')
            ->where('tab', 'cursos')
            ->has('member_area')
            ->has('member_area_id')
        );
    }

    public function test_aluno_cannot_access_member_area_admin_page(): void
    {
        $this->withoutInstallMiddleware();

        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);

        $this->actingAs($aluno)->get(route('member-area-admin.index'))->assertForbidden();
    }

    public function test_ensure_hub_is_created_automatically_when_missing(): void
    {
        $this->withoutInstallMiddleware();

        $owner = $this->infoprodutor();

        $this->assertNull(app(MemberHubService::class)->hubForTenant(1));

        $course = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'cur'.substr(uniqid('', true), -8),
        ]);

        $response = $this->actingAs($owner)->get(route('member-area-admin.index'));

        $hub = app(MemberHubService::class)->hubForTenant(1);
        $this->assertNotNull($hub);
        $this->assertTrue((bool) $hub->is_member_hub);
        $this->assertSame((string) $hub->id, (string) $course->fresh()->member_hub_product_id);

        $response->assertInertia(fn ($page) => $page
            ->where('member_area_id', $hub->id)
            ->has('member_area.member_area_url')
        );
    }

    public function test_courses_overview_includes_publication_and_vitrine_status(): void
    {
        $this->withoutInstallMiddleware();

        $owner = $this->infoprodutor();

        $hub = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'hub'.substr(uniqid('', true), -8),
            'is_member_hub' => true,
        ]);

        $vitrineCourse = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'vit'.substr(uniqid('', true), -8),
            'member_hub_product_id' => $hub->id,
            'is_active' => true,
        ]);

        $hiddenCourse = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'hid'.substr(uniqid('', true), -8),
            'member_hub_product_id' => $hub->id,
            'is_active' => false,
        ]);

        $vitrineSection = MemberSection::create([
            'product_id' => $hub->id,
            'title' => 'Vitrine teste',
            'position' => 1,
            'section_type' => 'products',
        ]);

        MemberModule::create([
            'member_section_id' => $vitrineSection->id,
            'product_id' => $hub->id,
            'title' => $vitrineCourse->name,
            'position' => 1,
            'related_product_id' => $vitrineCourse->id,
            'access_type' => 'paid',
        ]);

        $response = $this->actingAs($owner)->get(route('member-area-admin.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('member_area_id', $hub->id)
            ->has('courses', 2)
            ->where('courses', function ($courses) use ($vitrineCourse, $hiddenCourse) {
                $collection = collect($courses);

                return $collection->contains(fn ($c) => (string) $c['id'] === (string) $vitrineCourse->id
                    && $c['publication_label'] === 'Publicado'
                    && $c['in_vitrine'] === true)
                    && $collection->contains(fn ($c) => (string) $c['id'] === (string) $hiddenCourse->id
                        && $c['publication_label'] === 'Oculto'
                        && $c['in_vitrine'] === false);
            })
        );
    }

    public function test_vitrine_sections_included_in_page_payload(): void
    {
        $this->withoutInstallMiddleware();

        $owner = $this->infoprodutor();

        $hub = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'hub'.substr(uniqid('', true), -8),
            'is_member_hub' => true,
        ]);

        $section = MemberSection::create([
            'product_id' => $hub->id,
            'title' => 'Destaques',
            'position' => 1,
            'section_type' => 'products',
            'cover_mode' => 'horizontal',
        ]);

        $response = $this->actingAs($owner)->get(route('member-area-admin.index', ['tab' => 'vitrine']));

        $response->assertInertia(fn ($page) => $page
            ->where('tab', 'vitrine')
            ->where('vitrine_sections.0.id', $section->id)
            ->where('vitrine_sections.0.title', 'Destaques')
            ->where('vitrine_sections.0.cover_mode', 'horizontal')
        );
    }

    public function test_settings_update_persists_member_area_config(): void
    {
        $this->withoutInstallMiddleware();

        $owner = $this->infoprodutor();

        $hub = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'hub'.substr(uniqid('', true), -8),
            'is_member_hub' => true,
        ]);

        $this->actingAs($owner)
            ->postJson(route('member-area-admin.settings.update'), [
                'my_courses_title' => 'Meus Treinamentos',
                'my_courses_cover_mode' => 'horizontal',
                'email_subject' => 'Seu acesso chegou',
                'email_body_text' => 'Olá, {nome_cliente}',
            ])
            ->assertOk()
            ->assertJsonPath('member_area.my_courses_title', 'Meus Treinamentos');

        $hub->refresh();
        $this->assertSame('Meus Treinamentos', $hub->member_area_config['my_courses']['title']);
        $this->assertSame('horizontal', $hub->member_area_config['my_courses']['cover_mode']);
        $this->assertSame('Seu acesso chegou', $hub->checkout_config['email_template']['subject']);
    }

    public function test_alunos_tab_filters_member_area_courses_only(): void
    {
        $this->withoutInstallMiddleware();

        $owner = $this->infoprodutor();

        $hub = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'hub'.substr(uniqid('', true), -8),
            'is_member_hub' => true,
        ]);

        $memberCourse = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'mc'.substr(uniqid('', true), -8),
            'member_hub_product_id' => $hub->id,
        ]);

        $linkProduct = $this->createTestProduct([
            'type' => Product::TYPE_LINK,
            'checkout_slug' => 'lk'.substr(uniqid('', true), -8),
        ]);

        $alunoMember = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
            'name' => 'Aluno Curso',
        ]);
        $memberCourse->users()->attach($alunoMember->id);

        $alunoLink = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
            'name' => 'Aluno Link',
        ]);
        $linkProduct->users()->attach($alunoLink->id);

        $response = $this->actingAs($owner)->get(route('member-area-admin.index', ['tab' => 'alunos']));

        $response->assertInertia(fn ($page) => $page
            ->where('tab', 'alunos')
            ->has('produtos', 1)
            ->where('produtos.0.id', $memberCourse->id)
            ->where('alunos.total', 1)
            ->where('alunos.data.0.name', 'Aluno Curso')
        );
    }

    public function test_resend_access_email_for_member_course(): void
    {
        $this->withoutInstallMiddleware();
        Mail::fake();

        $owner = $this->infoprodutor();

        $course = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'cur'.substr(uniqid('', true), -8),
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $course->users()->attach($aluno->id);

        $this->mock(AccessEmailService::class, function ($mock) {
            $mock->shouldReceive('sendForUserProduct')->once()->andReturn(true);
        });

        $this->actingAs($owner)
            ->postJson(route('alunos.resend-access-email', $aluno))
            ->assertOk()
            ->assertJsonPath('success', true);
    }
}
