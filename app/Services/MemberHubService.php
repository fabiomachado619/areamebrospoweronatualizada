<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MemberHubService
{
    public function __construct(
        protected MemberAreaResolver $memberAreaResolver,
        protected MemberProgressService $memberProgressService,
    ) {}

    public function hubForTenant(?int $tenantId): ?Product
    {
        if ($tenantId === null) {
            return null;
        }

        return Product::query()
            ->where('tenant_id', $tenantId)
            ->where('type', Product::TYPE_AREA_MEMBROS)
            ->where('is_member_hub', true)
            ->first();
    }

    /**
     * URL absoluta oficial de login (/m/{slug}/login no path/local).
     */
    public function officialLoginUrl(?int $tenantId = null): ?string
    {
        return $this->memberAreaResolver->officialMemberAreaLoginUrl($tenantId);
    }

    /**
     * Path relativo oficial de login (/m/{slug}/login no path/local).
     */
    public function officialLoginPath(?int $tenantId = null): ?string
    {
        return $this->memberAreaResolver->officialMemberAreaLoginPath($tenantId);
    }

    /**
     * Garante um container interno da área de membros por tenant (HUB invisível ao admin).
     */
    public function ensureHubForTenant(int $tenantId): Product
    {
        $existing = $this->hubForTenant($tenantId);
        if ($existing) {
            $this->syncCoursesToHub($existing);

            return $existing->fresh();
        }

        $baseSlug = 'area-membros-'.$tenantId;
        $checkoutSlug = $baseSlug;
        $suffix = 0;
        while (Product::query()->where('checkout_slug', $checkoutSlug)->exists()) {
            $suffix++;
            $checkoutSlug = $baseSlug.'-'.$suffix;
        }

        $hubData = [
            'tenant_id' => $tenantId,
            'name' => 'Área de membros',
            'slug' => Str::slug($checkoutSlug),
            'checkout_slug' => $checkoutSlug,
            'type' => Product::TYPE_AREA_MEMBROS,
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => 0,
            'currency' => config('products.currency_default', 'BRL'),
            'is_active' => true,
            'member_area_config' => [
                'my_courses' => [
                    'enabled' => true,
                    'title' => (string) config('members.my_courses.title', 'Meus Cursos'),
                    'cover_mode' => (string) config('members.my_courses.cover_mode', 'vertical'),
                ],
            ],
        ];

        if (Product::query()->getConnection()->getDriverName() === 'sqlite') {
            $hubData['id'] = (string) ((int) (Product::query()->max('id') ?? 0) + 1);
        }

        $hub = new Product;
        $hub->forceFill($hubData);
        $hub->save();

        $this->designateHub($hub);

        return $hub->fresh();
    }

    public function syncCoursesToHub(Product $hub): void
    {
        if (! $hub->is_member_hub) {
            return;
        }

        Product::query()
            ->where('tenant_id', $hub->tenant_id)
            ->where('type', Product::TYPE_AREA_MEMBROS)
            ->where('is_member_hub', false)
            ->where('id', '!=', $hub->id)
            ->where(function ($q) use ($hub) {
                $q->whereNull('member_hub_product_id')
                    ->orWhere('member_hub_product_id', '!=', $hub->id);
            })
            ->update(['member_hub_product_id' => $hub->id]);
    }

    /**
     * @return Collection<int, Product>
     */
    public function enrolledCoursesForUser(Product $hub, User $user): Collection
    {
        if (! $hub->is_member_hub) {
            return collect();
        }

        return Product::query()
            ->where('tenant_id', $hub->tenant_id)
            ->where('type', Product::TYPE_AREA_MEMBROS)
            ->where('is_member_hub', false)
            ->where('id', '!=', $hub->id)
            ->whereHas('users', fn ($q) => $q->where('users.id', $user->id))
            ->orderBy('name')
            ->get();
    }

    /**
     * Seção virtual exibida apenas na home do hub.
     *
     * @return array<string, mixed>|null
     */
    public function buildMyCoursesSection(Product $hub, User $user): ?array
    {
        if (! $hub->is_member_hub) {
            return null;
        }

        $hubConfig = $hub->member_area_config['my_courses'] ?? [];
        if (array_key_exists('enabled', $hubConfig) && ! $hubConfig['enabled']) {
            return null;
        }

        $courses = $this->enrolledCoursesForUser($hub, $user);
        if ($courses->isEmpty()) {
            return null;
        }

        $storage = new StorageService($hub->tenant_id);
        $title = trim((string) ($hubConfig['title'] ?? ''));
        if ($title === '') {
            $title = (string) config('members.my_courses.title', 'Meus Cursos');
        }
        $coverMode = in_array($hubConfig['cover_mode'] ?? null, ['vertical', 'horizontal'], true)
            ? $hubConfig['cover_mode']
            : (string) config('members.my_courses.cover_mode', 'vertical');

        $modules = $courses->map(function (Product $course) use ($user, $storage) {
            $memberAreaUrl = $this->memberAreaResolver->baseUrlForProduct($course);

            return [
                'id' => 'course-'.$course->id,
                'title' => $course->name,
                'thumbnail' => $course->image ? $storage->url($course->image) : null,
                'show_title_on_cover' => true,
                'has_access' => true,
                'access_type' => 'paid',
                'related_product_id' => $course->id,
                'related_product' => [
                    'id' => $course->id,
                    'name' => $course->name,
                    'type' => $course->type,
                    'image_url' => $course->image ? $storage->url($course->image) : null,
                    'checkout_slug' => $course->checkout_slug,
                    'member_area_url' => $memberAreaUrl,
                ],
                'progress_percent' => $this->memberProgressService->completionPercent($course, $user),
            ];
        })->values()->all();

        return [
            'id' => 'my-courses',
            'title' => $title,
            'cover_mode' => $coverMode,
            'section_type' => 'my_courses',
            'position' => 0,
            'is_virtual' => true,
            'modules' => $modules,
        ];
    }

    /**
     * Oculta da vitrine cursos area_membros que o aluno já possui.
     *
     * @param  array<string, mixed>|null  $modulePayload
     * @return array<string, mixed>|null
     */
    public function filterVitrineModule(Product $hub, ?array $modulePayload, array $userProductIds): ?array
    {
        if ($modulePayload === null) {
            return null;
        }

        if (! $hub->is_member_hub) {
            return $modulePayload;
        }

        $relatedId = $modulePayload['related_product_id'] ?? null;
        $relatedType = $modulePayload['related_product']['type'] ?? null;
        if ($relatedId && $relatedType === Product::TYPE_AREA_MEMBROS && isset($userProductIds[$relatedId])) {
            return null;
        }

        return $modulePayload;
    }

    public function designateHub(Product $product): void
    {
        if ($product->type !== Product::TYPE_AREA_MEMBROS) {
            throw new \InvalidArgumentException('Apenas produtos do tipo área de membros podem ser hub.');
        }

        Product::query()
            ->where('tenant_id', $product->tenant_id)
            ->where('id', '!=', $product->id)
            ->where('is_member_hub', true)
            ->update(['is_member_hub' => false]);

        Product::query()
            ->where('tenant_id', $product->tenant_id)
            ->where('type', Product::TYPE_AREA_MEMBROS)
            ->where('id', '!=', $product->id)
            ->update(['member_hub_product_id' => $product->id]);

        $product->is_member_hub = true;
        $product->member_hub_product_id = null;
        $product->save();
    }

    public function clearHub(Product $product): void
    {
        if (! $product->is_member_hub) {
            return;
        }

        Product::query()
            ->where('member_hub_product_id', $product->id)
            ->update(['member_hub_product_id' => null]);

        $product->is_member_hub = false;
        $product->save();
    }
}
