<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDockerSetup;
use App\Http\Middleware\EnsureInstalled;
use App\Models\Product;
use App\Services\MemberHubService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

class MemberHubEnsureTest extends TestCase
{
    public function test_ensure_hub_for_tenant_creates_internal_hub_and_links_courses(): void
    {
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureDockerSetup::class,
            ValidateCsrfToken::class,
        ]);

        $course = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'cur'.substr(uniqid('', true), -8),
        ]);

        $hub = app(MemberHubService::class)->ensureHubForTenant(1);

        $this->assertTrue((bool) $hub->is_member_hub);
        $this->assertSame('Área de membros', $hub->name);
        $this->assertSame((string) $hub->id, (string) $course->fresh()->member_hub_product_id);
    }
}
