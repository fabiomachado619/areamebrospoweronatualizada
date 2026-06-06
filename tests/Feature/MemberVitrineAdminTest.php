<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDockerSetup;
use App\Http\Middleware\EnsureInstalled;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

class MemberVitrineAdminTest extends TestCase
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

    /**
     * @return array{hub: Product, section: MemberSection, course: Product}
     */
    private function vitrineFixture(): array
    {
        $hub = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'hub'.substr(uniqid('', true), -8),
            'is_member_hub' => true,
        ]);

        $course = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'cur'.substr(uniqid('', true), -8),
            'name' => 'Curso vitrine',
            'member_hub_product_id' => $hub->id,
        ]);

        $section = MemberSection::create([
            'product_id' => $hub->id,
            'title' => 'Destaques',
            'position' => 1,
            'section_type' => 'products',
        ]);

        return compact('hub', 'section', 'course');
    }

    public function test_vitrine_card_saves_title_subtitle_thumbnail_and_sales_link(): void
    {
        $this->withoutInstallMiddleware();
        $owner = $this->infoprodutor();
        ['hub' => $hub, 'section' => $section, 'course' => $course] = $this->vitrineFixture();

        $create = $this->actingAs($owner)->postJson(
            route('member-builder.modules.store', ['produto' => $hub, 'section' => $section]),
            [
                'title' => 'Card inicial',
                'related_product_id' => $course->id,
                'access_type' => 'paid',
                'single_card' => true,
            ]
        );

        $create->assertOk();
        $moduleId = $create->json('module.id');
        $this->assertNotNull($moduleId);
        $this->assertSame(1, MemberModule::where('member_section_id', $section->id)->count());

        $this->actingAs($owner)->putJson(
            route('member-builder.modules.update', ['produto' => $hub, 'module' => $moduleId]),
            [
                'title' => 'Título vitrine',
                'subtitle' => 'Descrição curta do curso',
                'thumbnail' => '/storage/member-area/capa-test.jpg',
                'external_url' => 'https://vendas.example/curso',
            ]
        )->assertOk();

        $module = MemberModule::find($moduleId);
        $this->assertSame('Título vitrine', $module->title);
        $this->assertSame('Descrição curta do curso', $module->subtitle);
        $this->assertSame('member-area/capa-test.jpg', $module->thumbnail);
        $this->assertSame('https://vendas.example/curso', $module->external_url);

        $this->actingAs($owner)->get(route('member-area-admin.index', ['tab' => 'vitrine']))
            ->assertInertia(fn ($page) => $page
                ->where('vitrine_sections.0.modules.0.title', 'Título vitrine')
                ->where('vitrine_sections.0.modules.0.subtitle', 'Descrição curta do curso')
                ->where('vitrine_sections.0.modules.0.external_url', 'https://vendas.example/curso')
                ->where('vitrine_sections.0.modules.0.thumbnail_url', '/storage/member-area/capa-test.jpg')
            );
    }

    public function test_vitrine_card_without_image_uses_product_fallback(): void
    {
        $this->withoutInstallMiddleware();
        $owner = $this->infoprodutor();
        ['hub' => $hub, 'section' => $section, 'course' => $course] = $this->vitrineFixture();

        $course->image = 'products/course-cover.jpg';
        $course->save();

        MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $hub->id,
            'title' => 'Sem capa própria',
            'position' => 1,
            'related_product_id' => $course->id,
            'access_type' => 'paid',
        ]);

        $this->actingAs($owner)->get(route('member-area-admin.index', ['tab' => 'vitrine']))
            ->assertInertia(fn ($page) => $page
                ->where('vitrine_sections.0.modules.0.thumbnail_url', '/storage/products/course-cover.jpg')
            );
    }

    public function test_student_sees_resolved_vitrine_cover_url(): void
    {
        $this->withoutInstallMiddleware();
        ['hub' => $hub, 'section' => $section, 'course' => $course] = $this->vitrineFixture();

        MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $hub->id,
            'title' => 'Com capa',
            'position' => 1,
            'related_product_id' => $course->id,
            'access_type' => 'paid',
            'thumbnail' => 'member-area/aluno-capa.jpg',
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);

        $this->actingAs($aluno)->get('/m/'.$hub->checkout_slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sections.0.modules.0.thumbnail', '/storage/member-area/aluno-capa.jpg')
                ->where('sections.0.modules.0.thumbnail_url', '/storage/member-area/aluno-capa.jpg')
            );
    }

    public function test_single_card_skips_module_import_for_area_membros(): void
    {
        $this->withoutInstallMiddleware();
        $owner = $this->infoprodutor();

        $source = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'src'.substr(uniqid('', true), -8),
        ]);

        $sourceSection = MemberSection::create([
            'product_id' => $source->id,
            'title' => 'Módulos',
            'position' => 1,
            'section_type' => 'courses',
        ]);

        MemberModule::create([
            'member_section_id' => $sourceSection->id,
            'product_id' => $source->id,
            'title' => 'M1',
            'position' => 1,
        ]);
        MemberModule::create([
            'member_section_id' => $sourceSection->id,
            'product_id' => $source->id,
            'title' => 'M2',
            'position' => 2,
        ]);

        $hub = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'hub'.substr(uniqid('', true), -8),
            'is_member_hub' => true,
        ]);

        $section = MemberSection::create([
            'product_id' => $hub->id,
            'title' => 'Vitrine',
            'position' => 1,
            'section_type' => 'products',
        ]);

        $this->actingAs($owner)->postJson(
            route('member-builder.modules.store', ['produto' => $hub, 'section' => $section]),
            [
                'related_product_id' => $source->id,
                'access_type' => 'paid',
                'single_card' => true,
            ]
        )->assertOk()
            ->assertJsonPath('message', 'Módulo criado.');

        $this->assertSame(1, MemberModule::where('member_section_id', $section->id)->count());
        $this->assertNull(MemberModule::where('member_section_id', $section->id)->value('source_member_module_id'));
    }

    public function test_duplicate_course_in_same_section_is_rejected(): void
    {
        $this->withoutInstallMiddleware();
        $owner = $this->infoprodutor();
        ['hub' => $hub, 'section' => $section, 'course' => $course] = $this->vitrineFixture();

        $this->actingAs($owner)->postJson(
            route('member-builder.modules.store', ['produto' => $hub, 'section' => $section]),
            [
                'title' => 'Primeiro',
                'related_product_id' => $course->id,
                'access_type' => 'paid',
                'single_card' => true,
            ]
        )->assertOk();

        $this->actingAs($owner)->postJson(
            route('member-builder.modules.store', ['produto' => $hub, 'section' => $section]),
            [
                'title' => 'Duplicado',
                'related_product_id' => $course->id,
                'access_type' => 'paid',
                'single_card' => true,
            ]
        )->assertStatus(422)
            ->assertJsonPath('message', 'Este curso já está nesta seção da vitrine.');

        $this->assertSame(1, MemberModule::where('member_section_id', $section->id)->count());
    }

    public function test_student_without_access_sees_vitrine_with_external_url(): void
    {
        $this->withoutInstallMiddleware();
        ['hub' => $hub, 'section' => $section, 'course' => $course] = $this->vitrineFixture();

        MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $hub->id,
            'title' => 'Oferta especial',
            'subtitle' => 'Aprenda do zero',
            'position' => 1,
            'related_product_id' => $course->id,
            'access_type' => 'paid',
            'external_url' => 'https://vendas.example/oferta',
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);

        $this->assertTrue($hub->fresh()->hasMemberAreaAccess($aluno));

        $this->actingAs($aluno)->get('/m/'.$hub->checkout_slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sections.0.section_type', 'products')
                ->where('sections.0.modules.0.title', 'Oferta especial')
                ->where('sections.0.modules.0.subtitle', 'Aprenda do zero')
                ->where('sections.0.modules.0.external_url', 'https://vendas.example/oferta')
                ->where('sections.0.modules.0.has_access', false)
            );
    }

    public function test_student_with_access_hides_course_from_vitrine_and_shows_in_my_courses(): void
    {
        $this->withoutInstallMiddleware();

        $hubSlug = 'hub'.substr(uniqid('', true), -8);
        $courseSlug = 'cur'.substr(uniqid('', true), -8);

        $hub = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $hubSlug,
            'is_member_hub' => true,
        ]);

        $course = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $courseSlug,
            'name' => 'Meu curso',
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
            'title' => 'Meu curso',
            'subtitle' => 'Descrição',
            'position' => 1,
            'related_product_id' => $course->id,
            'access_type' => 'paid',
            'external_url' => 'https://vendas.example/nao-deve-usar',
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $course->users()->attach($aluno->id);

        $this->actingAs($aluno)->get('/m/'.$hubSlug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sections', function ($sections) use ($course) {
                    $collection = collect($sections);
                    $myCourses = $collection->firstWhere('section_type', 'my_courses');
                    $visibleVitrine = $collection->first(
                        fn ($s) => ($s['section_type'] ?? '') === 'products' && count($s['modules'] ?? []) > 0
                    );

                    return $myCourses !== null
                        && (string) ($myCourses['modules'][0]['related_product_id'] ?? '') === (string) $course->id
                        && $visibleVitrine === null;
                })
            );
    }

    public function test_student_without_external_url_falls_back_to_checkout(): void
    {
        $this->withoutInstallMiddleware();
        ['hub' => $hub, 'section' => $section, 'course' => $course] = $this->vitrineFixture();

        MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $hub->id,
            'title' => 'Sem link externo',
            'position' => 1,
            'related_product_id' => $course->id,
            'access_type' => 'paid',
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);

        $this->actingAs($aluno)->get('/m/'.$hub->checkout_slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sections.0.modules.0.external_url', null)
                ->where('sections.0.modules.0.related_product.checkout_url', url('/c/'.$course->checkout_slug))
                ->where('sections.0.modules.0.has_access', false)
            );
    }

    public function test_external_links_section_still_works(): void
    {
        $this->withoutInstallMiddleware();
        $owner = $this->infoprodutor();

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'area'.substr(uniqid('', true), -8),
        ]);

        $section = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Links',
            'position' => 1,
            'section_type' => 'external_links',
        ]);

        $this->actingAs($owner)->postJson(
            route('member-builder.modules.store', ['produto' => $product, 'section' => $section]),
            [
                'title' => 'Site parceiro',
                'external_url' => 'https://parceiro.example',
            ]
        )->assertOk();

        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $product->users()->attach($aluno->id);

        $this->actingAs($aluno)->get('/m/'.$product->checkout_slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sections.0.section_type', 'external_links')
                ->where('sections.0.modules.0.external_url', 'https://parceiro.example')
            );
    }
}
