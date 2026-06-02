<?php

namespace Tests\Feature;

use App\Events\SubscriptionCancelled;
use App\Events\SubscriptionPastDue;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionLifecycleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function createSubscriptionProduct(array $subscriptionSettings = []): Product
    {
        $config = Product::defaultCheckoutConfig();
        $config['subscription'] = array_merge(Product::defaultSubscriptionSettings(), $subscriptionSettings);

        return $this->createTestProduct([
            'billing_type' => Product::BILLING_SUBSCRIPTION,
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_config' => $config,
        ]);
    }

    private function createSubscriptionForProduct(
        Product $product,
        User $user,
        string $periodEnd,
        string $status = Subscription::STATUS_ACTIVE,
    ): Subscription {
        $plan = SubscriptionPlan::create([
            'product_id' => $product->id,
            'name' => 'Mensal',
            'price' => 99,
            'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'checkout_slug' => SubscriptionPlan::generateUniqueCheckoutSlug(),
            'position' => 1,
        ]);

        return Subscription::create([
            'tenant_id' => $product->tenant_id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'status' => $status,
            'current_period_start' => Carbon::parse($periodEnd)->subMonth()->toDateString(),
            'current_period_end' => $periodEnd,
            'renewal_token' => Subscription::generateRenewalToken(),
        ]);
    }

    public function test_access_and_renewable_until_use_product_settings(): void
    {
        $product = $this->createSubscriptionProduct([
            'grace_period_days' => 3,
            'renewal_window_days' => 10,
        ]);
        $user = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_ALUNO]);
        $sub = $this->createSubscriptionForProduct($product, $user, '2026-06-01');

        $lifecycle = app(SubscriptionLifecycleService::class);

        $this->assertSame('2026-06-04', $lifecycle->accessUntil($sub)?->toDateString());
        $this->assertSame('2026-06-11', $lifecycle->renewableUntil($sub)?->toDateString());
    }

    public function test_should_send_reminder_only_once_per_day(): void
    {
        $product = $this->createSubscriptionProduct(['notify_days_before' => 5, 'renewal_window_days' => 7]);
        $user = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_ALUNO]);
        $sub = $this->createSubscriptionForProduct($product, $user, Carbon::today()->addDays(2)->toDateString());

        $lifecycle = app(SubscriptionLifecycleService::class);
        $today = Carbon::today();

        $this->assertTrue($lifecycle->shouldSendReminderToday($sub, $today));

        $lifecycle->markReminderSent($sub, $today);
        $sub->refresh();

        $this->assertFalse($lifecycle->shouldSendReminderToday($sub, $today));
    }

    public function test_process_daily_marks_past_due_and_dispatches_event(): void
    {
        Event::fake([SubscriptionPastDue::class]);

        $product = $this->createSubscriptionProduct([
            'grace_period_days' => 0,
            'renewal_window_days' => 7,
        ]);
        $user = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_ALUNO]);
        $sub = $this->createSubscriptionForProduct($product, $user, Carbon::today()->subDays(1)->toDateString());

        app(SubscriptionLifecycleService::class)->processDaily(Carbon::today());

        $sub->refresh();
        $this->assertSame(Subscription::STATUS_PAST_DUE, $sub->status);
        Event::assertDispatched(SubscriptionPastDue::class);
    }

    public function test_cancel_subscription_dispatches_event(): void
    {
        Event::fake([SubscriptionCancelled::class]);

        $product = $this->createSubscriptionProduct();
        $user = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_ALUNO]);
        $sub = $this->createSubscriptionForProduct($product, $user, Carbon::today()->addMonth()->toDateString());

        app(SubscriptionLifecycleService::class)->cancelSubscription($sub);

        $sub->refresh();
        $this->assertSame(Subscription::STATUS_CANCELLED, $sub->status);
        $this->assertNotNull($sub->cancelled_at);
        Event::assertDispatched(SubscriptionCancelled::class);
    }

    public function test_cancel_api_requires_manage_permission(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createSubscriptionProduct();
        $buyer = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_ALUNO]);
        $sub = $this->createSubscriptionForProduct($product, $buyer, Carbon::today()->addMonth()->toDateString());

        Event::fake([SubscriptionCancelled::class]);

        $this->actingAs($owner)
            ->postJson(route('assinaturas.cancel', $sub))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(Subscription::STATUS_CANCELLED, $sub->fresh()->status);
        Event::assertDispatched(SubscriptionCancelled::class);
    }

    public function test_can_renew_within_window_for_past_due(): void
    {
        $product = $this->createSubscriptionProduct(['renewal_window_days' => 7]);
        $user = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_ALUNO]);
        $sub = $this->createSubscriptionForProduct(
            $product,
            $user,
            Carbon::today()->subDays(2)->toDateString(),
            Subscription::STATUS_PAST_DUE
        );

        $lifecycle = app(SubscriptionLifecycleService::class);
        $this->assertTrue($lifecycle->canRenew($sub, Carbon::today()));
    }

    public function test_cannot_renew_after_renewal_window(): void
    {
        $product = $this->createSubscriptionProduct(['renewal_window_days' => 3]);
        $user = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_ALUNO]);
        $sub = $this->createSubscriptionForProduct(
            $product,
            $user,
            Carbon::today()->subDays(10)->toDateString(),
            Subscription::STATUS_PAST_DUE
        );

        $lifecycle = app(SubscriptionLifecycleService::class);
        $this->assertFalse($lifecycle->canRenew($sub, Carbon::today()));
    }
}
