<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\MemberLesson;
use App\Models\MemberLessonProgress;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use Tests\TestCase;

class MemberBuilderReorderTest extends TestCase
{
    public function test_reorder_sections_updates_positions(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'reord'.substr(uniqid('', true), -8),
            'slug' => 'p-'.substr(uniqid('', true), -8),
        ]);

        $sectionA = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'A',
            'position' => 1,
            'cover_mode' => 'vertical',
            'section_type' => 'courses',
        ]);
        $sectionB = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'B',
            'position' => 2,
            'cover_mode' => 'vertical',
            'section_type' => 'courses',
        ]);

        $response = $this->actingAs($owner)->postJson(route('member-builder.sections.reorder', $product), [
            'sections' => [
                ['id' => $sectionB->id, 'position' => 1],
                ['id' => $sectionA->id, 'position' => 2],
            ],
        ]);

        $response->assertOk()->assertJson(['message' => 'Ordem salva com sucesso.']);
        $this->assertSame(2, $sectionA->fresh()->position);
        $this->assertSame(1, $sectionB->fresh()->position);
    }

    public function test_reorder_modules_and_move_between_sections(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'reorm'.substr(uniqid('', true), -8),
            'slug' => 'p-'.substr(uniqid('', true), -8),
        ]);

        $sectionA = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Seção A',
            'position' => 1,
            'cover_mode' => 'vertical',
            'section_type' => 'courses',
        ]);
        $sectionB = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Seção B',
            'position' => 2,
            'cover_mode' => 'vertical',
            'section_type' => 'courses',
        ]);

        $moduleA = MemberModule::create([
            'member_section_id' => $sectionA->id,
            'product_id' => $product->id,
            'title' => 'Mod A',
            'position' => 1,
        ]);
        $moduleB = MemberModule::create([
            'member_section_id' => $sectionA->id,
            'product_id' => $product->id,
            'title' => 'Mod B',
            'position' => 2,
        ]);

        $response = $this->actingAs($owner)->postJson(route('member-builder.modules.reorder', $product), [
            'modules' => [
                ['id' => $moduleB->id, 'section_id' => $sectionB->id, 'position' => 1],
                ['id' => $moduleA->id, 'section_id' => $sectionA->id, 'position' => 1],
            ],
        ]);

        $response->assertOk();
        $this->assertSame($sectionB->id, $moduleB->fresh()->member_section_id);
        $this->assertSame(1, $moduleB->fresh()->position);
    }

    public function test_reorder_lessons_and_move_between_modules_preserves_progress(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'reorl'.substr(uniqid('', true), -8),
            'slug' => 'p-'.substr(uniqid('', true), -8),
        ]);

        $section = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Seção',
            'position' => 1,
            'cover_mode' => 'vertical',
            'section_type' => 'courses',
        ]);

        $moduleA = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Mod A',
            'position' => 1,
        ]);
        $moduleB = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Mod B',
            'position' => 2,
        ]);

        $lessonA = MemberLesson::create([
            'member_module_id' => $moduleA->id,
            'product_id' => $product->id,
            'title' => 'Aula A',
            'position' => 1,
            'type' => MemberLesson::TYPE_TEXT,
        ]);
        $lessonB = MemberLesson::create([
            'member_module_id' => $moduleA->id,
            'product_id' => $product->id,
            'title' => 'Aula B',
            'position' => 2,
            'type' => MemberLesson::TYPE_TEXT,
        ]);

        $aluno = User::factory()->create(['role' => User::ROLE_ALUNO, 'tenant_id' => 1]);
        MemberLessonProgress::create([
            'user_id' => $aluno->id,
            'member_lesson_id' => $lessonA->id,
            'product_id' => $product->id,
            'completed_at' => now(),
            'progress_percent' => 100,
        ]);

        $response = $this->actingAs($owner)->postJson(route('member-builder.lessons.reorder', $product), [
            'lessons' => [
                ['id' => $lessonB->id, 'module_id' => $moduleB->id, 'position' => 1],
                ['id' => $lessonA->id, 'module_id' => $moduleA->id, 'position' => 1],
            ],
        ]);

        $response->assertOk();
        $this->assertSame($moduleB->id, $lessonB->fresh()->member_module_id);
        $this->assertSame(1, $lessonB->fresh()->position);
        $this->assertNotNull(MemberLessonProgress::where('member_lesson_id', $lessonA->id)->first()?->completed_at);
    }

    public function test_cannot_move_module_between_different_section_types(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'reort'.substr(uniqid('', true), -8),
            'slug' => 'p-'.substr(uniqid('', true), -8),
        ]);

        $courses = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Cursos',
            'position' => 1,
            'cover_mode' => 'vertical',
            'section_type' => 'courses',
        ]);
        $links = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Links',
            'position' => 2,
            'cover_mode' => 'vertical',
            'section_type' => 'external_links',
        ]);

        $module = MemberModule::create([
            'member_section_id' => $courses->id,
            'product_id' => $product->id,
            'title' => 'Mod',
            'position' => 1,
        ]);

        $response = $this->actingAs($owner)->postJson(route('member-builder.modules.reorder', $product), [
            'modules' => [
                ['id' => $module->id, 'section_id' => $links->id, 'position' => 1],
            ],
        ]);

        $response->assertStatus(422);
    }
}
