<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDockerSetup;
use App\Http\Middleware\EnsureInstalled;
use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use Tests\TestCase;

class MemberAreaHomeNavigationTest extends TestCase
{
    /**
     * @return array{hub: Product, course: Product, module: MemberModule, lesson: MemberLesson, aluno: User, hubSlug: string, courseSlug: string}
     */
    private function createHubCourseWithLesson(): array
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
            'name' => 'Curso vinculado',
            'member_hub_product_id' => $hub->id,
            'member_area_config' => [
                'certificate' => ['enabled' => true, 'completion_percent' => 100],
            ],
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
            'title' => 'Módulo 1',
            'position' => 1,
        ]);

        $lesson = MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $course->id,
            'title' => 'Aula teste',
            'position' => 1,
            'type' => MemberLesson::TYPE_TEXT,
            'content_text' => '<p>Conteúdo</p>',
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $course->users()->attach($aluno->id);

        return compact('hub', 'course', 'module', 'lesson', 'aluno', 'hubSlug', 'courseSlug');
    }

    public function test_hub_course_internal_pages_share_hub_home_url(): void
    {
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureDockerSetup::class,
        ]);

        $ctx = $this->createHubCourseWithLesson();
        $expectedHome = '/m/'.$ctx['hubSlug'];

        $this->actingAs($ctx['aluno'])
            ->get('/m/'.$ctx['courseSlug'].'/modulo/'.$ctx['module']->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('home_url', $expectedHome)
                ->where('hub_slug', $ctx['hubSlug'])
            );

        $this->actingAs($ctx['aluno'])
            ->get('/m/'.$ctx['courseSlug'].'/aula/'.$ctx['lesson']->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('home_url', $expectedHome));

        $this->actingAs($ctx['aluno'])
            ->get('/m/'.$ctx['courseSlug'].'/certificado')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('home_url', $expectedHome));
    }

    public function test_standalone_course_keeps_own_home_url(): void
    {
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureDockerSetup::class,
        ]);

        $courseSlug = 'solo'.substr(uniqid('', true), -8);

        $course = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $courseSlug,
            'name' => 'Curso standalone',
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
            'title' => 'Módulo 1',
            'position' => 1,
        ]);

        MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $course->id,
            'title' => 'Aula',
            'position' => 1,
            'type' => MemberLesson::TYPE_TEXT,
            'content_text' => '<p>Ok</p>',
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $course->users()->attach($aluno->id);

        $expectedHome = '/m/'.$courseSlug;

        $this->actingAs($aluno)
            ->get('/m/'.$courseSlug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('home_url', $expectedHome)
                ->where('hub_slug', null)
            );

        $this->actingAs($aluno)
            ->get('/m/'.$courseSlug.'/modulo/'.$module->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('home_url', $expectedHome));
    }

    public function test_hub_home_page_points_to_itself(): void
    {
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureDockerSetup::class,
        ]);

        $hubSlug = 'hub'.substr(uniqid('', true), -8);

        $hub = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $hubSlug,
            'is_member_hub' => true,
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $hub->users()->attach($aluno->id);

        $this->actingAs($aluno)
            ->get('/m/'.$hubSlug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('home_url', '/m/'.$hubSlug)
                ->where('hub_slug', $hubSlug)
            );
    }
}
