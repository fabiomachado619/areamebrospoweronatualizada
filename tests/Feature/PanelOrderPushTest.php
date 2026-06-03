<?php

namespace Tests\Feature;

use App\Events\BoletoGenerated;
use App\Events\OrderCompleted;
use App\Http\Middleware\EnsureInstalled;
use App\Models\Order;
use App\Models\PanelNotification;
use App\Models\PanelPushSubscription;
use App\Models\Product;
use App\Models\User;
use Tests\TestCase;

class PanelOrderPushTest extends TestCase
{
    public function test_order_completed_creates_sale_notification_for_card_payment(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $product = $this->createTestProduct(['name' => 'Produto Cartão']);
        $user = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => $product->tenant_id,
        ]);

        PanelPushSubscription::create([
            'user_id' => $user->id,
            'tenant_id' => $product->tenant_id,
            'endpoint' => 'https://example.com/push/card-endpoint',
            'keys' => ['auth' => 'auth-key', 'p256dh' => 'p256dh-key'],
            'preferences' => ['pix' => true, 'boleto' => true, 'card' => true],
        ]);

        $order = Order::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 150,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
            'gateway' => 'cajupay',
            'metadata' => ['checkout_payment_method' => 'card'],
        ]);
        $order->setRelation('product', $product);

        event(new OrderCompleted($order));

        $this->assertDatabaseHas('panel_notifications', [
            'user_id' => $user->id,
            'type' => 'sale_approved',
            'event_key' => 'sale_' . $order->id,
        ]);

        $notification = PanelNotification::where('event_key', 'sale_' . $order->id)->first();
        $this->assertNotNull($notification);
        $this->assertStringContainsString('Cartão', (string) $notification->body);
    }

    public function test_boleto_generated_creates_boleto_notification(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $product = $this->createTestProduct(['name' => 'Produto Boleto']);
        $user = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => $product->tenant_id,
        ]);

        PanelPushSubscription::create([
            'user_id' => $user->id,
            'tenant_id' => $product->tenant_id,
            'endpoint' => 'https://example.com/push/boleto-endpoint',
            'keys' => ['auth' => 'auth-key', 'p256dh' => 'p256dh-key'],
        ]);

        $order = Order::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 89.9,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
            'gateway' => 'efi',
            'metadata' => ['checkout_payment_method' => 'boleto'],
        ]);
        $order->setRelation('product', $product);

        event(new BoletoGenerated($order, ['amount' => 89.9]));

        $this->assertDatabaseHas('panel_notifications', [
            'user_id' => $user->id,
            'type' => 'boleto_generated',
            'event_key' => 'boleto_' . $order->id,
        ]);
    }

    public function test_push_preferences_update_endpoint(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $user = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        PanelPushSubscription::create([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'endpoint' => 'https://example.com/push/prefs-endpoint',
            'keys' => ['auth' => 'auth-key', 'p256dh' => 'p256dh-key'],
        ]);

        $response = $this->actingAs($user)->patchJson('/painel/push-preferences', [
            'preferences' => [
                'pix' => true,
                'boleto' => false,
                'card' => true,
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('preferences.boleto', false)
            ->assertJsonPath('preferences.card', true);

        $this->assertDatabaseHas('panel_push_subscriptions', [
            'user_id' => $user->id,
            'endpoint' => 'https://example.com/push/prefs-endpoint',
        ]);

        $sub = PanelPushSubscription::where('endpoint', 'https://example.com/push/prefs-endpoint')->first();
        $this->assertFalse($sub->preferences['boleto']);
        $this->assertTrue($sub->preferences['card']);
    }
}
