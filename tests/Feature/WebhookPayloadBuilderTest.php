<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Jobs\DispatchWebhookJob;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\Webhook;
use App\Support\WebhookPayloadBuilder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookPayloadBuilderTest extends TestCase
{
    public function test_order_payload_includes_offer_and_product_details(): void
    {
        $product = $this->createTestProduct([
            'name' => 'Curso Premium',
            'checkout_slug' => 'curso-premium',
        ]);

        $offer = ProductOffer::create([
            'product_id' => $product->id,
            'name' => 'Oferta Black Friday',
            'price' => 149.90,
            'currency' => 'BRL',
            'position' => 0,
        ]);

        $order = Order::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'product_offer_id' => $offer->id,
            'status' => 'completed',
            'amount' => 149.90,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
            'gateway' => 'cajupay',
            'metadata' => [
                'checkout_payment_method' => 'pix',
                'utm_source' => 'meta',
            ],
        ]);

        $payload = WebhookPayloadBuilder::forOrderEvent($order);

        $this->assertSame('Curso Premium', $payload['product']['name']);
        $this->assertSame('Oferta Black Friday', $payload['offer']['name']);
        $this->assertSame('pix', $payload['payment']['method']);
        $this->assertSame('meta', $payload['tracking']['utm_source']);
        $this->assertSame('buyer@test.com', $payload['customer']['email']);
        $this->assertArrayNotHasKey('order_bumps', $payload);
        $this->assertArrayNotHasKey('products', $payload);
        $this->assertArrayNotHasKey('email', $payload['order']);
    }

    public function test_order_payload_includes_order_bump_line_items(): void
    {
        $main = $this->createTestProduct(['name' => 'Produto principal']);
        $bumpProduct = $this->createTestProduct(['name' => 'Order bump extra']);

        $order = Order::create([
            'tenant_id' => $main->tenant_id,
            'product_id' => $main->id,
            'status' => 'completed',
            'amount' => 120,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
            'gateway' => 'cajupay',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $main->id,
            'amount' => 100,
            'position' => 0,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $bumpProduct->id,
            'amount' => 20,
            'position' => 1,
        ]);

        $payload = WebhookPayloadBuilder::forOrderEvent($order->fresh());

        $this->assertCount(1, $payload['order_bumps']);
        $this->assertSame('Order bump extra', $payload['order_bumps'][0]['name']);
        $this->assertSame(20.0, $payload['order_bumps'][0]['amount']);
        $this->assertSame('Produto principal', $payload['product']['name']);
    }

    public function test_tracking_merges_checkout_session_utms(): void
    {
        $product = $this->createTestProduct();

        $order = Order::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
            'metadata' => [],
        ]);

        CheckoutSession::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'tok-'.uniqid('', true),
            'step' => CheckoutSession::STEP_CONVERTED,
            'order_id' => $order->id,
            'utm_source' => 'google',
            'utm_campaign' => 'spring',
        ]);

        $payload = WebhookPayloadBuilder::forOrderEvent($order);

        $this->assertSame('google', $payload['tracking']['utm_source']);
        $this->assertSame('spring', $payload['tracking']['utm_campaign']);
    }

    public function test_tracking_does_not_include_unlisted_metadata_keys(): void
    {
        $product = $this->createTestProduct();

        $order = Order::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
            'metadata' => [
                'utm_source' => 'meta',
                'fbp' => 'should-not-appear',
                'custom_field' => 'ignored',
            ],
        ]);

        CheckoutSession::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'tok-'.uniqid('', true),
            'step' => CheckoutSession::STEP_CONVERTED,
            'order_id' => $order->id,
            'tracking_metadata' => [
                'fbp' => 'fb.1.example',
                'fbc' => 'should-not-appear',
                'gclid' => 'abc123',
            ],
        ]);

        $payload = WebhookPayloadBuilder::forOrderEvent($order);

        $this->assertSame('meta', $payload['tracking']['utm_source']);
        $this->assertSame('abc123', $payload['tracking']['gclid']);
        $this->assertArrayNotHasKey('fbp', $payload['tracking']);
        $this->assertArrayNotHasKey('fbc', $payload['tracking']);
        $this->assertArrayNotHasKey('custom_field', $payload['tracking']);
    }

    public function test_subscription_payload_includes_lifecycle_fields(): void
    {
        $product = $this->createTestProduct([
            'billing_type' => Product::BILLING_SUBSCRIPTION,
            'checkout_config' => array_merge(Product::defaultCheckoutConfig(), [
                'subscription' => [
                    'grace_period_days' => 2,
                    'notify_days_before' => 3,
                    'renewal_window_days' => 7,
                ],
            ]),
        ]);

        $plan = SubscriptionPlan::create([
            'product_id' => $product->id,
            'name' => 'Mensal',
            'price' => 50,
            'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'checkout_slug' => SubscriptionPlan::generateUniqueCheckoutSlug(),
            'position' => 1,
        ]);

        $user = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_ALUNO]);
        $periodEnd = Carbon::today()->subDays(1);

        $subscription = Subscription::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'status' => Subscription::STATUS_PAST_DUE,
            'current_period_start' => $periodEnd->copy()->subMonth()->toDateString(),
            'current_period_end' => $periodEnd->toDateString(),
            'renewal_token' => Subscription::generateRenewalToken(),
        ]);

        $payload = WebhookPayloadBuilder::forSubscriptionEvent($subscription);

        $this->assertSame(Subscription::STATUS_PAST_DUE, $payload['subscription']['status']);
        $this->assertArrayHasKey('access_until', $payload['subscription']);
        $this->assertArrayHasKey('renewable_until', $payload['subscription']);
        $this->assertArrayHasKey('days_overdue', $payload['subscription']);
        $this->assertSame($periodEnd->copy()->addDays(2)->toDateString(), $payload['subscription']['access_until']);
        $this->assertArrayNotHasKey('products', $payload);
        $this->assertSame('Mensal', $payload['subscription_plan']['name']);
    }

    public function test_dispatch_webhook_job_sends_product_name(): void
    {
        Http::fake(['https://hook.example/*' => Http::response('ok', 200)]);

        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => $owner->tenant_id, 'name' => 'Webhook Product']);

        $webhook = Webhook::create([
            'tenant_id' => $owner->tenant_id,
            'name' => 'Test hook',
            'url' => 'https://hook.example/callback',
            'events' => [OrderCompleted::class],
            'is_active' => true,
        ]);

        $order = Order::create([
            'tenant_id' => $owner->tenant_id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 99,
            'currency' => 'BRL',
            'email' => 'hook@test.com',
        ]);

        $payload = WebhookPayloadBuilder::forOrderEvent($order);
        (new DispatchWebhookJob($webhook->id, OrderCompleted::class, $payload))->handle();

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return ($body['payload']['product']['name'] ?? null) === 'Webhook Product'
                && $body['event'] === 'pedido_pago';
        });
    }
}
