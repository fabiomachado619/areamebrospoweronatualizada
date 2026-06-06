<?php

namespace App\Services;

use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class MemberAreaAdminService
{
    public function __construct(
        protected MemberHubService $memberHubService,
        protected MemberAreaResolver $memberAreaResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildPageData(int $tenantId, Request $request): array
    {
        $hub = $this->memberHubService->ensureHubForTenant($tenantId);
        $memberAreaProducts = $this->memberAreaProductsQuery($tenantId)->get();

        $vitrineProductIds = $this->vitrineRelatedProductIds($hub);

        $courses = $memberAreaProducts
            ->where('is_member_hub', false)
            ->map(fn (Product $course) => $this->mapCourseRow($course, $vitrineProductIds))
            ->values()
            ->all();

        return [
            'member_area' => $this->mapMemberAreaSettings($hub),
            'member_area_id' => $hub->id,
            'courses' => $courses,
            'vitrine_sections' => $this->vitrineSectionsPayload($hub),
        ];
    }

    /**
     * @return Collection<int, string>
     */
    public function vitrineRelatedProductIds(Product $hub): Collection
    {
        return MemberModule::query()
            ->whereIn('member_section_id', function ($q) use ($hub) {
                $q->select('id')
                    ->from('member_sections')
                    ->where('product_id', $hub->id)
                    ->where('section_type', 'products');
            })
            ->whereNotNull('related_product_id')
            ->pluck('related_product_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Product>
     */
    private function memberAreaProductsQuery(int $tenantId)
    {
        return Product::query()
            ->where('tenant_id', $tenantId)
            ->where('type', Product::TYPE_AREA_MEMBROS)
            ->withCount('users')
            ->orderBy('name');
    }

    /**
     * @param  Collection<int, string>  $vitrineProductIds
     * @return array<string, mixed>
     */
    private function mapCourseRow(Product $course, Collection $vitrineProductIds): array
    {
        $inVitrine = $vitrineProductIds->contains($course->id);
        $storage = new StorageService($course->tenant_id);

        return [
            'id' => $course->id,
            'name' => $course->name,
            'checkout_slug' => $course->checkout_slug,
            'image_url' => $course->image ? $storage->url($course->image) : null,
            'is_active' => (bool) $course->is_active,
            'publication_label' => $course->is_active ? 'Publicado' : 'Oculto',
            'in_vitrine' => $inVitrine,
            'in_vitrine_label' => $inVitrine ? 'Sim' : 'Não',
            'students_count' => (int) ($course->users_count ?? 0),
            'member_builder_url' => url('/produtos/'.$course->id.'/member-builder'),
            'edit_url' => url('/produtos/'.$course->id.'/edit'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapMemberAreaSettings(Product $hub): array
    {
        $hubConfig = $hub->member_area_config ?? [];
        $myCourses = $hubConfig['my_courses'] ?? [];
        $emailTemplate = is_array($hub->checkout_config['email_template'] ?? null)
            ? $hub->checkout_config['email_template']
            : [];

        return [
            'member_area_url' => $this->memberAreaResolver->baseUrlForProduct($hub),
            'my_courses_title' => trim((string) ($myCourses['title'] ?? '')) ?: (string) config('members.my_courses.title', 'Meus Cursos'),
            'my_courses_cover_mode' => in_array($myCourses['cover_mode'] ?? null, ['vertical', 'horizontal'], true)
                ? $myCourses['cover_mode']
                : (string) config('members.my_courses.cover_mode', 'vertical'),
            'email_subject' => (string) ($emailTemplate['subject'] ?? Product::defaultEmailTemplate()['subject'] ?? 'Seu acesso'),
            'email_body_text' => (string) ($emailTemplate['body_text'] ?? ''),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function vitrineSectionsPayload(Product $hub): array
    {
        $storage = new StorageService($hub->tenant_id);
        $sections = MemberSection::query()
            ->where('product_id', $hub->id)
            ->where('section_type', 'products')
            ->orderBy('position')
            ->with(['modules' => fn ($q) => $q->orderBy('position')->with('relatedProduct:id,name,checkout_slug,image')])
            ->get();

        return $sections->map(function (MemberSection $section) use ($storage) {
            return [
                'id' => $section->id,
                'title' => $section->title,
                'position' => $section->position,
                'cover_mode' => $section->cover_mode ?? 'vertical',
                'modules' => $section->modules->map(function (MemberModule $module) use ($storage) {
                    $related = $module->relatedProduct;

                    return [
                        'id' => $module->id,
                        'title' => $module->title,
                        'subtitle' => $module->subtitle,
                        'position' => $module->position,
                        'thumbnail' => $module->thumbnail,
                        'thumbnail_url' => $this->moduleThumbnailUrl($module->thumbnail, $storage, $related),
                        'external_url' => $module->external_url,
                        'related_product_id' => $module->related_product_id,
                        'related_product_name' => $related?->name,
                        'related_product_image_url' => $related?->image ? $storage->url($related->image) : null,
                        'access_type' => $module->access_type ?? 'paid',
                    ];
                })->values()->all(),
            ];
        })->values()->all();
    }

    private function moduleThumbnailUrl(?string $thumbnail, StorageService $storage, ?Product $related): ?string
    {
        $resolved = $storage->resolvePublicUrl($thumbnail);
        if ($resolved) {
            return $resolved;
        }
        if ($related?->image) {
            return $storage->url($related->image);
        }

        return null;
    }
}
