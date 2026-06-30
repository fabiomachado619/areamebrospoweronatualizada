<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use Tests\TestCase;

class MemberLessonNavigationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        ]);
    }

    private function createCourseWithLessons(string $slug): array
    {
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $slug,
            'name' => 'Curso teste',
        ]);

        $section = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Conteúdo',
            'position' => 1,
            'section_type' => 'courses',
        ]);

        $module1 = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Módulo 1',
            'position' => 1,
        ]);

        $module2 = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Módulo 2',
            'position' => 2,
        ]);

        $lesson1 = MemberLesson::create([
            'member_module_id' => $module1->id,
            'product_id' => $product->id,
            'title' => 'Aula 1',
            'position' => 1,
            'type' => MemberLesson::TYPE_TEXT,
            'content_text' => '<p>1</p>',
        ]);

        $lesson2 = MemberLesson::create([
            'member_module_id' => $module1->id,
            'product_id' => $product->id,
            'title' => 'Aula 2',
            'position' => 2,
            'type' => MemberLesson::TYPE_TEXT,
            'content_text' => '<p>2</p>',
        ]);

        $lesson3 = MemberLesson::create([
            'member_module_id' => $module2->id,
            'product_id' => $product->id,
            'title' => 'Aula 3',
            'position' => 1,
            'type' => MemberLesson::TYPE_TEXT,
            'content_text' => '<p>3</p>',
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $product->users()->attach($aluno->id);

        return compact('product', 'module1', 'module2', 'lesson1', 'lesson2', 'lesson3', 'aluno');
    }

    public function test_next_endpoint_returns_next_lesson_in_same_module(): void
    {
        $slug = 'nav'.substr(uniqid('', true), -8);
        ['lesson1' => $lesson1, 'lesson2' => $lesson2, 'module1' => $module1, 'aluno' => $aluno] = $this->createCourseWithLessons($slug);

        $response = $this->actingAs($aluno)->getJson('/m/'.$slug.'/aula/'.$lesson1->id.'/next');

        $response->assertOk();
        $response->assertJsonPath('navigation.has_next', true);
        $response->assertJsonPath('navigation.is_course_end', false);
        $this->assertStringContainsString(
            '/modulo/'.$module1->id.'?aula='.$lesson2->id,
            (string) $response->json('navigation.redirect_url')
        );
    }

    public function test_next_endpoint_returns_first_lesson_of_next_module(): void
    {
        $slug = 'nav'.substr(uniqid('', true), -8);
        ['lesson2' => $lesson2, 'lesson3' => $lesson3, 'module2' => $module2, 'aluno' => $aluno] = $this->createCourseWithLessons($slug);

        $response = $this->actingAs($aluno)->getJson('/m/'.$slug.'/aula/'.$lesson2->id.'/next');

        $response->assertOk();
        $response->assertJsonPath('navigation.has_next', true);
        $this->assertStringContainsString(
            '/modulo/'.$module2->id.'?aula='.$lesson3->id,
            (string) $response->json('navigation.redirect_url')
        );
    }

    public function test_next_endpoint_marks_course_end_on_last_lesson(): void
    {
        $slug = 'nav'.substr(uniqid('', true), -8);
        ['lesson3' => $lesson3, 'aluno' => $aluno] = $this->createCourseWithLessons($slug);

        $response = $this->actingAs($aluno)->getJson('/m/'.$slug.'/aula/'.$lesson3->id.'/next');

        $response->assertOk();
        $response->assertJsonPath('navigation.has_next', false);
        $response->assertJsonPath('navigation.is_course_end', true);
        $response->assertJsonPath('navigation.redirect_url', null);
    }

    public function test_next_endpoint_does_not_mark_lesson_completed(): void
    {
        $slug = 'nav'.substr(uniqid('', true), -8);
        ['lesson1' => $lesson1, 'aluno' => $aluno] = $this->createCourseWithLessons($slug);

        $this->actingAs($aluno)->getJson('/m/'.$slug.'/aula/'.$lesson1->id.'/next')->assertOk();

        $this->assertDatabaseMissing('member_lesson_progress', [
            'user_id' => $aluno->id,
            'member_lesson_id' => $lesson1->id,
        ]);
    }

    public function test_complete_endpoint_returns_navigation_without_persisting_on_next_only(): void
    {
        $slug = 'nav'.substr(uniqid('', true), -8);
        ['lesson1' => $lesson1, 'lesson2' => $lesson2, 'module1' => $module1, 'aluno' => $aluno] = $this->createCourseWithLessons($slug);

        $response = $this->actingAs($aluno)->postJson('/m/'.$slug.'/aula/'.$lesson1->id.'/complete');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('navigation.has_next', true);
        $this->assertStringContainsString(
            '/modulo/'.$module1->id.'?aula='.$lesson2->id,
            (string) $response->json('navigation.redirect_url')
        );

        $this->assertDatabaseHas('member_lesson_progress', [
            'user_id' => $aluno->id,
            'member_lesson_id' => $lesson1->id,
        ]);
    }

    public function test_complete_endpoint_returns_course_end_on_last_lesson(): void
    {
        $slug = 'nav'.substr(uniqid('', true), -8);
        ['lesson3' => $lesson3, 'aluno' => $aluno] = $this->createCourseWithLessons($slug);

        $response = $this->actingAs($aluno)->postJson('/m/'.$slug.'/aula/'.$lesson3->id.'/complete');

        $response->assertOk();
        $response->assertJsonPath('navigation.has_next', false);
        $response->assertJsonPath('navigation.is_course_end', true);
    }

    public function test_embedded_module_uses_wrapper_id_in_next_url(): void
    {
        $hostSlug = 'hostnav'.substr(uniqid('', true), -6);
        $sourceSlug = 'srcnav'.substr(uniqid('', true), -6);

        $sourceProduct = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $sourceSlug,
            'name' => 'Origem',
        ]);

        $sourceSection = MemberSection::create([
            'product_id' => $sourceProduct->id,
            'title' => 'Aulas',
            'position' => 1,
            'section_type' => 'courses',
        ]);

        $sourceModule = MemberModule::create([
            'member_section_id' => $sourceSection->id,
            'product_id' => $sourceProduct->id,
            'title' => 'M origem',
            'position' => 1,
        ]);

        $lessonA = MemberLesson::create([
            'member_module_id' => $sourceModule->id,
            'product_id' => $sourceProduct->id,
            'title' => 'A',
            'position' => 1,
            'type' => MemberLesson::TYPE_TEXT,
            'content_text' => '<p>A</p>',
        ]);

        $lessonB = MemberLesson::create([
            'member_module_id' => $sourceModule->id,
            'product_id' => $sourceProduct->id,
            'title' => 'B',
            'position' => 2,
            'type' => MemberLesson::TYPE_TEXT,
            'content_text' => '<p>B</p>',
        ]);

        $hostProduct = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $hostSlug,
            'name' => 'Host',
        ]);

        $productsSection = MemberSection::create([
            'product_id' => $hostProduct->id,
            'title' => 'Produtos',
            'position' => 1,
            'section_type' => 'products',
        ]);

        $wrapper = MemberModule::create([
            'member_section_id' => $productsSection->id,
            'product_id' => $hostProduct->id,
            'title' => 'Embed',
            'position' => 1,
            'related_product_id' => $sourceProduct->id,
            'source_member_module_id' => $sourceModule->id,
            'access_type' => 'paid',
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $hostProduct->users()->attach($aluno->id);
        $sourceProduct->users()->attach($aluno->id);

        $response = $this->actingAs($aluno)->getJson('/m/'.$hostSlug.'/aula/'.$lessonA->id.'/next');

        $response->assertOk();
        $response->assertJsonPath('navigation.has_next', true);
        $this->assertStringContainsString(
            '/modulo/'.$wrapper->id.'?aula='.$lessonB->id,
            (string) $response->json('navigation.redirect_url')
        );
    }
}
