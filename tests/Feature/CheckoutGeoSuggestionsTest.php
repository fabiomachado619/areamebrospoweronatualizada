<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\User;
use Tests\TestCase;

class CheckoutGeoSuggestionsTest extends TestCase
{
    public function test_cf_ip_country_header_sets_suggestions_on_checkout_page(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'name' => 'Geo test product',
            'price' => 10,
        ]);

        $slug = $product->checkout_slug;
        $this->assertNotEmpty($slug);

        $response = $this->withHeader('CF-IPCountry', 'US')
            ->get('/c/'.$slug);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('suggested_country_code', 'US')
            ->where('suggested_locale', 'en')
            ->where('suggested_currency', 'USD'));
    }

    public function test_vercel_country_header_sets_suggestions(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'name' => 'Geo Vercel',
            'price' => 10,
        ]);

        $response = $this->withHeader('X-Vercel-IP-Country', 'ES')
            ->get('/c/'.$product->checkout_slug);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('suggested_country_code', 'ES')
            ->where('suggested_locale', 'es')
            ->where('suggested_currency', 'EUR'));
    }

    public function test_mexico_country_suggests_mxn(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'name' => 'Geo MX',
            'price' => 10,
        ]);

        $response = $this->withHeader('CF-IPCountry', 'MX')
            ->get('/c/'.$product->checkout_slug);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('suggested_country_code', 'MX')
            ->where('suggested_currency', 'MXN'));
    }

    public function test_checkout_force_overrides_geo_suggestions(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'name' => 'Force locale product',
            'price' => 10,
            'checkout_config' => [
                'checkout_currency' => [
                    'mode' => 'fixed',
                    'currency' => 'BRL',
                ],
                'checkout_force' => [
                    'enabled' => true,
                    'locale' => 'pt_BR',
                ],
            ],
        ]);

        $response = $this->withHeader('CF-IPCountry', 'US')
            ->get('/c/'.$product->checkout_slug);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('suggested_country_code', 'US')
            ->where('suggested_locale', 'pt_BR')
            ->where('suggested_currency', 'BRL'));
    }

    public function test_fixed_currency_product_returns_single_currency_on_checkout(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'name' => 'Fixed USD product',
            'price' => 10,
            'checkout_config' => [
                'checkout_currency' => [
                    'mode' => 'fixed',
                    'currency' => 'USD',
                ],
            ],
        ]);

        $response = $this->withHeader('CF-IPCountry', 'BR')
            ->get('/c/'.$product->checkout_slug);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('suggested_currency', 'USD')
            ->has('currencies', 1)
            ->where('currencies.0.code', 'USD'));
    }
}
