<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDockerSetup;
use App\Http\Middleware\EnsureInstalled;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use App\Services\MemberHubService;
use Tests\TestCase;

class MemberHubMinimalTest extends TestCase
{
    public function test_hub_shows_my_courses_and_hides_enrolled_from_vitrine(): void
    {
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureDockerSetup::class,
        ]);

        $hubSlug = 'hub'.substr(uniqid('', true), -8);
        $enrolledSlug = 'cur-a'.substr(uniqid('', true), -6);
        $vitrineSlug = 'cur-b'.substr(uniqid('', true), -6);

        $hub = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $hubSlug,
            'name' => 'HUB Principal',
            'is_member_hub' => true,
        ]);

        $enrolledCourse = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $enrolledSlug,
            'name' => 'Curso liberado',
            'member_hub_product_id' => $hub->id,
        ]);

        $vitrineCourse = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $vitrineSlug,
            'name' => 'Curso vitrine',
            'member_hub_product_id' => $hub->id,
        ]);

        $vitrineSection = MemberSection::create([
            'product_id' => $hub->id,
            'title' => 'Vitrine',
            'position' => 1,
            'section_type' => 'products',
        ]);

        MemberModule::create([
            'member_section_id' => $vitrineSection->id,
            'product_id' => $hub->id,
            'title' => 'Curso liberado',
            'position' => 1,
            'related_product_id' => $enrolledCourse->id,
            'access_type' => 'paid',
        ]);

        MemberModule::create([
            'member_section_id' => $vitrineSection->id,
            'product_id' => $hub->id,
            'title' => 'Curso vitrine',
            'position' => 2,
            'related_product_id' => $vitrineCourse->id,
            'access_type' => 'paid',
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $enrolledCourse->users()->attach($aluno->id);

        $this->assertTrue($hub->fresh()->hasMemberAreaAccess($aluno));

        $response = $this->actingAs($aluno)->get('/m/'.$hubSlug);
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('is_member_hub', true)
            ->where('sections.0.section_type', 'my_courses')
            ->where('sections.0.modules.0.related_product_id', $enrolledCourse->id)
            ->where('sections.1.section_type', 'products')
            ->where('sections.1.modules', fn ($mods) => count($mods) === 1
                && (int) $mods[0]['related_product_id'] === (int) $vitrineCourse->id)
        );
    }

    public function test_designate_hub_sets_single_hub_per_tenant(): void
    {
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureDockerSetup::class,
        ]);

        $hubA = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'hub-a'.substr(uniqid('', true), -6),
            'is_member_hub' => true,
        ]);

        $hubB = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'hub-b'.substr(uniqid('', true), -6),
        ]);

        $course = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'cur'.substr(uniqid('', true), -6),
        ]);

        app(MemberHubService::class)->designateHub($hubB);

        $this->assertFalse((bool) $hubA->fresh()->is_member_hub);
        $this->assertTrue((bool) $hubB->fresh()->is_member_hub);
        $this->assertSame((string) $hubB->id, (string) $course->fresh()->member_hub_product_id);
    }

    public function test_non_hub_area_is_unchanged(): void
    {
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureDockerSetup::class,
        ]);

        $slug = 'curso'.substr(uniqid('', true), -8);
        $course = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $slug,
            'name' => 'Curso standalone',
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
            'title' => 'Módulo 1',
            'position' => 1,
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $course->users()->attach($aluno->id);

        $response = $this->actingAs($aluno)->get('/m/'.$slug);
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('is_member_hub', false)
            ->where('sections', fn ($sections) => collect($sections)->every(
                fn ($s) => ($s['section_type'] ?? 'courses') !== 'my_courses'
            ))
            ->where('sections.0.section_type', 'courses')
        );
    }

    public function test_hub_allows_aluno_without_courses_to_see_vitrine_only(): void
    {
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureDockerSetup::class,
        ]);

        $hubSlug = 'hub0'.substr(uniqid('', true), -7);

        $hub = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $hubSlug,
            'name' => 'HUB Vitrine',
            'is_member_hub' => true,
        ]);

        $course = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'vitr'.substr(uniqid('', true), -6),
            'name' => 'Curso vitrine',
            'member_hub_product_id' => $hub->id,
        ]);

        $vitrineSection = MemberSection::create([
            'product_id' => $hub->id,
            'title' => 'Vitrine',
            'position' => 1,
            'section_type' => 'products',
        ]);

        MemberModule::create([
            'member_section_id' => $vitrineSection->id,
            'product_id' => $hub->id,
            'title' => 'Curso vitrine',
            'position' => 1,
            'related_product_id' => $course->id,
            'access_type' => 'paid',
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);

        $this->assertTrue($hub->fresh()->hasMemberAreaAccess($aluno));

        $response = $this->actingAs($aluno)->get('/m/'.$hubSlug);
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('is_member_hub', true)
            ->where('sections', fn ($sections) => collect($sections)->every(
                fn ($s) => ($s['section_type'] ?? 'courses') !== 'my_courses'
            ))
            ->where('sections.0.section_type', 'products')
            ->where('sections.0.modules.0.related_product_id', $course->id)
        );
    }
}
