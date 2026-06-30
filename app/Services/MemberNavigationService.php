<?php

namespace App\Services;

use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MemberNavigationService
{
    /**
     * Resolve a próxima aula navegável após a aula atual, respeitando ordem, locks e embeds.
     *
     * @return array{has_next: bool, redirect_url: string|null, is_course_end: bool}
     */
    public function resolveNextNavigation(Product $hostProduct, User $user, MemberLesson $currentLesson, string $baseUrl): array
    {
        $sequence = $this->buildNavigationSequence($hostProduct, $user);
        $currentIndex = $this->findLessonIndex($sequence, (int) $currentLesson->id);

        if ($currentIndex === null) {
            return $this->courseEndResult();
        }

        for ($i = $currentIndex + 1, $count = count($sequence); $i < $count; $i++) {
            $entry = $sequence[$i];
            if ($entry['is_accessible']) {
                return [
                    'has_next' => true,
                    'redirect_url' => $this->lessonUrl($baseUrl, $entry['route_module_id'], $entry['lesson_id']),
                    'is_course_end' => false,
                ];
            }
        }

        return $this->courseEndResult();
    }

    /**
     * @return array{has_next: bool, redirect_url: string|null, is_course_end: bool}
     */
    private function courseEndResult(): array
    {
        return [
            'has_next' => false,
            'redirect_url' => null,
            'is_course_end' => true,
        ];
    }

    private function lessonUrl(string $baseUrl, int $routeModuleId, int $lessonId): string
    {
        $base = rtrim($baseUrl, '/');

        return "{$base}/modulo/{$routeModuleId}?aula={$lessonId}";
    }

    /**
     * @param  list<array{lesson_id: int, route_module_id: int, is_accessible: bool}>  $sequence
     */
    private function findLessonIndex(array $sequence, int $lessonId): ?int
    {
        foreach ($sequence as $index => $entry) {
            if ($entry['lesson_id'] === $lessonId) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return list<array{lesson_id: int, route_module_id: int, is_accessible: bool}>
     */
    private function buildNavigationSequence(Product $hostProduct, User $user): array
    {
        $accessStartAt = $this->userAccessStartAt($hostProduct, $user);
        $now = now();
        $userProductIds = $user->products()->pluck('products.id')->flip()->all();

        $sections = $hostProduct->memberSections()
            ->with(['modules' => fn ($q) => $q->orderBy('position')])
            ->orderBy('position')
            ->get();

        $sequence = [];

        foreach ($sections as $section) {
            $sectionType = $section->section_type ?? 'courses';

            if ($sectionType === 'courses') {
                foreach ($section->modules as $module) {
                    $sequence = array_merge(
                        $sequence,
                        $this->sequenceForCourseModule($module, $module->id, $accessStartAt, $now)
                    );
                }

                continue;
            }

            if ($sectionType === 'products') {
                foreach ($section->modules as $wrapper) {
                    if (! $this->canIncludeEmbeddedModule($wrapper, $userProductIds)) {
                        continue;
                    }
                    $effective = $this->resolveContentModuleForWrapper($wrapper);
                    $sequence = array_merge(
                        $sequence,
                        $this->sequenceForCourseModule($wrapper, $wrapper->id, $accessStartAt, $now, $effective)
                    );
                }
            }
        }

        return $sequence;
    }

    /**
     * @param  array<int|string, int>  $userProductIds
     */
    private function canIncludeEmbeddedModule(MemberModule $wrapper, array $userProductIds): bool
    {
        if (! $wrapper->source_member_module_id || ! $wrapper->related_product_id) {
            return false;
        }

        $related = Product::query()->find($wrapper->related_product_id);
        if (! $related || $related->type !== Product::TYPE_AREA_MEMBROS) {
            return false;
        }

        $accessType = $wrapper->access_type ?? 'paid';
        if ($accessType === 'free') {
            return true;
        }

        return isset($userProductIds[$wrapper->related_product_id])
            || isset($userProductIds[(string) $wrapper->related_product_id]);
    }

    /**
     * @return list<array{lesson_id: int, route_module_id: int, is_accessible: bool}>
     */
    private function sequenceForCourseModule(
        MemberModule $routeModule,
        int $routeModuleId,
        Carbon $accessStartAt,
        Carbon $now,
        ?MemberModule $contentModule = null,
    ): array {
        $contentModule ??= $routeModule;

        if (! $contentModule->relationLoaded('lessons')) {
            $contentModule->load(['lessons' => fn ($q) => $q->orderBy('position')]);
        }

        if ($contentModule->lessons->isEmpty()) {
            return [];
        }

        $moduleLock = $this->moduleLockPayload($contentModule, $accessStartAt, $now);
        $moduleAccessible = ($moduleLock['is_locked'] ?? false) !== true;

        $entries = [];
        foreach ($contentModule->lessons as $lesson) {
            $lessonLock = $this->lessonLockPayload($lesson, $contentModule, $accessStartAt, $now);
            $lessonAccessible = ($lessonLock['is_locked'] ?? false) !== true;

            $entries[] = [
                'lesson_id' => (int) $lesson->id,
                'route_module_id' => $routeModuleId,
                'is_accessible' => $moduleAccessible && $lessonAccessible,
            ];
        }

        return $entries;
    }

    private function resolveContentModuleForWrapper(MemberModule $wrapper): MemberModule
    {
        if (! $wrapper->source_member_module_id) {
            if (! $wrapper->relationLoaded('lessons')) {
                $wrapper->load(['lessons' => fn ($q) => $q->orderBy('position')]);
            }

            return $wrapper;
        }

        $source = MemberModule::query()
            ->whereKey($wrapper->source_member_module_id)
            ->where('product_id', $wrapper->related_product_id)
            ->with(['lessons' => fn ($q) => $q->orderBy('position')])
            ->first();

        if (! $source) {
            return $wrapper;
        }

        return $source;
    }

    private function userAccessStartAt(Product $product, User $user): Carbon
    {
        if ($user->canAccessPanel() && $user->tenant_id === $product->tenant_id) {
            return now()->subYears(20);
        }

        $createdAt = DB::table('product_user')
            ->where('product_id', $product->id)
            ->where('user_id', $user->id)
            ->value('created_at');

        if ($createdAt) {
            return Carbon::parse($createdAt);
        }

        return now();
    }

    private function scheduleMeta(?int $afterDays, mixed $atDate, Carbon $accessStartAt): array
    {
        if ($atDate instanceof Carbon) {
            return ['available_at' => $atDate->copy()->startOfDay(), 'mode' => 'date'];
        }
        if (is_string($atDate) && $atDate !== '') {
            return ['available_at' => Carbon::createFromFormat('Y-m-d', $atDate)->startOfDay(), 'mode' => 'date'];
        }
        if (is_int($afterDays) && $afterDays > 0) {
            return ['available_at' => $accessStartAt->copy()->addDays($afterDays), 'mode' => 'days'];
        }

        return ['available_at' => null, 'mode' => null];
    }

    private function lockPayload(?Carbon $availableAt, Carbon $now, ?string $mode): array
    {
        if (! $availableAt) {
            return ['is_locked' => false, 'available_at' => null, 'lock_message' => null];
        }
        if ($availableAt->lessThanOrEqualTo($now)) {
            return ['is_locked' => false, 'available_at' => $availableAt->toIso8601String(), 'lock_message' => null];
        }

        return ['is_locked' => true, 'available_at' => $availableAt->toIso8601String(), 'lock_message' => null];
    }

    private function moduleLockPayload(MemberModule $module, Carbon $accessStartAt, Carbon $now): array
    {
        $meta = $this->scheduleMeta($module->release_after_days, $module->release_at_date, $accessStartAt);

        return $this->lockPayload($meta['available_at'], $now, $meta['mode']);
    }

    private function lessonLockPayload(MemberLesson $lesson, ?MemberModule $module, Carbon $accessStartAt, Carbon $now): array
    {
        $lessonMeta = $this->scheduleMeta($lesson->release_after_days, $lesson->release_at_date, $accessStartAt);
        $moduleMeta = $module
            ? $this->scheduleMeta($module->release_after_days, $module->release_at_date, $accessStartAt)
            : ['available_at' => null, 'mode' => null];

        $lessonAt = $lessonMeta['available_at'];
        $moduleAt = $moduleMeta['available_at'];
        $availableAt = null;
        $mode = null;

        if ($lessonAt && $moduleAt) {
            if ($lessonAt->greaterThanOrEqualTo($moduleAt)) {
                $availableAt = $lessonAt;
                $mode = $lessonMeta['mode'];
            } else {
                $availableAt = $moduleAt;
                $mode = $moduleMeta['mode'];
            }
        } elseif ($lessonAt) {
            $availableAt = $lessonAt;
            $mode = $lessonMeta['mode'];
        } elseif ($moduleAt) {
            $availableAt = $moduleAt;
            $mode = $moduleMeta['mode'];
        }

        return $this->lockPayload($availableAt, $now, $mode);
    }
}
