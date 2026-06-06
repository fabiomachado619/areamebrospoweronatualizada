<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use Tests\TestCase;

class MemberBuilderModuleThumbnailTest extends TestCase
{
    public function test_member_builder_resolves_module_thumbnail_to_public_storage_url(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'mbthumb'.substr(uniqid('', true), -8),
            'slug' => 'p-'.substr(uniqid('', true), -8),
        ]);

        $section = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Seção',
            'position' => 1,
            'cover_mode' => 'vertical',
            'section_type' => 'courses',
        ]);

        MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Módulo com capa',
            'position' => 1,
            'thumbnail' => 'member-area/modulo-capa.jpg',
        ]);

        $response = $this->actingAs($owner)->get(route('member-builder.index', $product));

        $response->assertStatus(200);
        $response->assertViewHas('produto', function (array $payload) {
            $mod = $this->findModuleInBuilderPayload($payload, 'Módulo com capa');
            if ($mod === null) {
                return false;
            }

            return ($mod['thumbnail'] ?? '') === '/storage/member-area/modulo-capa.jpg'
                && ($mod['thumbnail_url'] ?? '') === '/storage/member-area/modulo-capa.jpg';
        });
    }

    public function test_member_builder_module_without_thumbnail_has_empty_cover_urls(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'mbnoimg'.substr(uniqid('', true), -8),
            'slug' => 'p-'.substr(uniqid('', true), -8),
        ]);

        $section = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Seção',
            'position' => 1,
            'cover_mode' => 'vertical',
            'section_type' => 'courses',
        ]);

        MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Módulo sem capa',
            'position' => 1,
        ]);

        $response = $this->actingAs($owner)->get(route('member-builder.index', $product));

        $response->assertStatus(200);
        $response->assertViewHas('produto', function (array $payload) {
            $mod = $this->findModuleInBuilderPayload($payload, 'Módulo sem capa');
            if ($mod === null) {
                return false;
            }

            return empty($mod['thumbnail'] ?? null) && empty($mod['thumbnail_url'] ?? null);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function findModuleInBuilderPayload(array $payload, string $title): ?array
    {
        foreach ($payload['sections'] ?? [] as $section) {
            foreach ($section['modules'] ?? [] as $module) {
                if (($module['title'] ?? '') === $title) {
                    return $module;
                }
            }
        }

        return null;
    }
}
