<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CheckoutOfferPlanPublicIdTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureInstalled::class);
    }

    public function test_checkout_resolves_offer_by_public_id(): void
    {
        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct(['price' => 100]);
        $offer = ProductOffer::create([
            'product_id' => $product->id,
            'name' => 'Oferta especial',
            'price' => 49.90,
            'currency' => 'BRL',
            'position' => 1,
        ]);

        $response = $this->get('/c/'.$product->checkout_slug.'?offer='.$offer->public_id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('product.price', 49.90)
            ->where('product.product_offer_id', $offer->id)
            ->where('offer.id', $offer->id));
    }

    public function test_checkout_resolves_offer_by_legacy_offer_id(): void
    {
        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct(['price' => 100]);
        $offer = ProductOffer::create([
            'product_id' => $product->id,
            'name' => 'Oferta legado',
            'price' => 59.90,
            'currency' => 'BRL',
            'position' => 1,
        ]);

        $response = $this->get('/c/'.$product->checkout_slug.'?offer_id='.$offer->id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('product.price', 59.90)
            ->where('product.product_offer_id', $offer->id)
            ->where('offer.id', $offer->id));
    }

    public function test_checkout_ignores_offer_public_id_from_other_product(): void
    {
        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $productA = $this->createTestProduct(['price' => 100]);
        $productB = $this->createTestProduct(['price' => 200]);
        $offerOnB = ProductOffer::create([
            'product_id' => $productB->id,
            'name' => 'Oferta B',
            'price' => 75,
            'currency' => 'BRL',
            'position' => 1,
        ]);

        $response = $this->get('/c/'.$productA->checkout_slug.'?offer='.$offerOnB->public_id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('product.price', 100.0)
            ->where('product.product_offer_id', null)
            ->where('offer', null));
    }

    public function test_checkout_resolves_plan_by_public_id(): void
    {
        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'billing_type' => Product::BILLING_SUBSCRIPTION,
            'price' => 100,
        ]);
        $plan = SubscriptionPlan::create([
            'product_id' => $product->id,
            'name' => 'Mensal',
            'price' => 29.90,
            'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'position' => 0,
        ]);

        $response = $this->get('/c/'.$product->checkout_slug.'?plan='.$plan->public_id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('product.price', 29.90)
            ->where('product.subscription_plan_id', $plan->id)
            ->where('subscription_plan.id', $plan->id));
    }

    public function test_checkout_resolves_plan_by_legacy_plan_id(): void
    {
        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'billing_type' => Product::BILLING_SUBSCRIPTION,
            'price' => 100,
        ]);
        $plan = SubscriptionPlan::create([
            'product_id' => $product->id,
            'name' => 'Anual',
            'price' => 199.90,
            'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_ANNUAL,
            'position' => 1,
        ]);

        $response = $this->get('/c/'.$product->checkout_slug.'?plan_id='.$plan->id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('product.price', 199.90)
            ->where('product.subscription_plan_id', $plan->id)
            ->where('subscription_plan.id', $plan->id));
    }

    public function test_checkout_ignores_plan_public_id_from_other_product(): void
    {
        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $productA = $this->createTestProduct([
            'billing_type' => Product::BILLING_SUBSCRIPTION,
            'price' => 50,
        ]);
        $productB = $this->createTestProduct([
            'billing_type' => Product::BILLING_SUBSCRIPTION,
            'price' => 80,
        ]);
        SubscriptionPlan::create([
            'product_id' => $productA->id,
            'name' => 'Plano A',
            'price' => 19.90,
            'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'position' => 0,
        ]);
        $planOnB = SubscriptionPlan::create([
            'product_id' => $productB->id,
            'name' => 'Plano B',
            'price' => 39.90,
            'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'position' => 0,
        ]);

        $response = $this->get('/c/'.$productA->checkout_slug.'?plan='.$planOnB->public_id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('product.price', 19.90)
            ->where('product.subscription_plan_id', fn ($id) => $id !== $planOnB->id));
    }

    public function test_backfill_command_fills_missing_public_ids(): void
    {
        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct();
        $offer = ProductOffer::create([
            'product_id' => $product->id,
            'name' => 'Sem public_id',
            'price' => 10,
            'currency' => 'BRL',
            'position' => 1,
        ]);
        DB::table('product_offers')->where('id', $offer->id)->update(['public_id' => null]);

        $plan = SubscriptionPlan::create([
            'product_id' => $product->id,
            'name' => 'Plano sem public_id',
            'price' => 20,
            'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'position' => 0,
        ]);
        DB::table('subscription_plans')->where('id', $plan->id)->update(['public_id' => null]);

        $this->artisan('checkout:backfill-offer-plan-public-ids')
            ->assertSuccessful();

        $offer->refresh();
        $plan->refresh();

        $this->assertNotEmpty($offer->public_id);
        $this->assertNotEmpty($plan->public_id);
        $this->assertSame(10, strlen($offer->public_id));
        $this->assertSame(10, strlen($plan->public_id));
    }
}
