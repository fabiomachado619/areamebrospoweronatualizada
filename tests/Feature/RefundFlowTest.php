<?php

namespace Tests\Feature;

use App\Events\OrderRefunded;
use App\Http\Middleware\EnsureInstalled;
use App\Models\User;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Product;
use App\Models\RefundRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class RefundFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_revoke_access_on_refunded_event(): void
    {
        $tenantId = 1;
        $user = User::factory()->create(['tenant_id' => $tenantId, 'role' => User::ROLE_ALUNO]);
        $product = $this->createTestProduct([
            'name' => 'Curso',
            'type' => Product::TYPE_AREA_MEMBROS,
        ]);
        $product->users()->attach($user->id);

        $order = Order::create([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'amount' => 50,
            'currency' => 'BRL',
            'email' => $user->email,
            'status' => 'refunded',
            'gateway' => 'cajupay',
        ]);

        event(new OrderRefunded($order));

        $this->assertFalse($product->users()->where('user_id', $user->id)->exists());
    }

    public function test_webhook_refund_marks_order_and_revokes_access(): void
    {
        Event::fake([OrderRefunded::class]);

        $paymentId = (string) Str::uuid();
        $tenantId = 1;
        $user = User::factory()->create(['tenant_id' => $tenantId, 'role' => User::ROLE_ALUNO]);
        $product = $this->createTestProduct([
            'name' => 'Curso',
            'type' => Product::TYPE_AREA_MEMBROS,
        ]);
        $product->users()->attach($user->id);

        $order = Order::create([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'amount' => 50,
            'currency' => 'BRL',
            'email' => $user->email,
            'status' => 'completed',
            'gateway' => 'cajupay',
            'gateway_id' => $paymentId,
            'metadata' => ['checkout_payment_method' => 'pix', 'cajupay_payment_id' => $paymentId],
        ]);

        RefundRequest::create([
            'tenant_id' => $tenantId,
            'order_id' => $order->id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'reason' => 'Motivo de teste com tamanho suficiente.',
            'status' => RefundRequest::STATUS_PROCESSING,
            'mode' => RefundRequest::MODE_AUTO,
            'gateway' => 'cajupay',
            'cajupay_payment_id' => $paymentId,
            'client_refund_id' => 'getfy-order-'.$order->id.'-refund',
        ]);

        $credential = new GatewayCredential([
            'tenant_id' => $tenantId,
            'gateway_slug' => 'cajupay',
            'is_connected' => true,
        ]);
        $credential->setEncryptedCredentials(['public_key' => 'pk', 'secret_key' => 'sk']);
        $credential->save();

        Http::fake([
            '*/api/payments*' => Http::response(['status' => 'refunded'], 200),
        ]);

        $job = new ProcessPaymentWebhook('cajupay', $paymentId, 'order.refunded', 'refunded', [
            'webhook_source' => 'cajupay_hmac_verified',
        ]);
        $job->handle();

        $order->refresh();
        $this->assertSame('refunded', $order->status);
        Event::assertDispatched(OrderRefunded::class);
    }

    public function test_member_can_post_refund_request(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $tenantId = 1;
        $user = User::factory()->create(['tenant_id' => $tenantId, 'role' => User::ROLE_ALUNO]);
        $slug = 'refund1';
        $product = $this->createTestProduct([
            'name' => 'Curso',
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $slug,
            'member_area_config' => ['refund' => ['enabled' => true, 'days' => 7, 'mode' => 'manual']],
        ]);
        $product->users()->attach($user->id);

        Order::create([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'amount' => 50,
            'currency' => 'BRL',
            'email' => $user->email,
            'status' => 'completed',
            'gateway' => 'manual',
        ]);

        $response = $this->actingAs($user)->postJson("/m/{$slug}/refund", [
            'reason' => 'Quero cancelar porque não atendeu minhas expectativas.',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('refund_requests', [
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => RefundRequest::STATUS_PENDING,
        ]);
    }

    public function test_vendas_refund_resolves_buyer_by_email_when_order_user_id_null(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $tenantId = 1;
        $admin = User::factory()->create(['tenant_id' => $tenantId, 'role' => User::ROLE_INFOPRODUTOR]);
        $buyer = User::factory()->create(['tenant_id' => $tenantId, 'role' => User::ROLE_ALUNO]);
        $product = $this->createTestProduct(['name' => 'Curso', 'type' => Product::TYPE_AREA_MEMBROS]);

        $order = Order::create([
            'tenant_id' => $tenantId,
            'user_id' => null,
            'product_id' => $product->id,
            'amount' => 50,
            'currency' => 'BRL',
            'email' => $buyer->email,
            'status' => 'completed',
            'gateway' => 'manual',
        ]);

        $response = $this->actingAs($admin)->postJson("/vendas/{$order->id}/refund");

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('refund_requests', [
            'order_id' => $order->id,
            'user_id' => $buyer->id,
            'status' => RefundRequest::STATUS_COMPLETED,
        ]);
    }

    public function test_vendas_refund_marks_non_cajupay_order_refunded(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $tenantId = 1;
        $admin = User::factory()->create(['tenant_id' => $tenantId, 'role' => User::ROLE_INFOPRODUTOR]);
        $buyer = User::factory()->create(['tenant_id' => $tenantId, 'role' => User::ROLE_ALUNO]);
        $product = $this->createTestProduct(['name' => 'Curso', 'type' => Product::TYPE_AREA_MEMBROS]);
        $product->users()->attach($buyer->id);

        $order = Order::create([
            'tenant_id' => $tenantId,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'amount' => 99,
            'currency' => 'BRL',
            'email' => $buyer->email,
            'status' => 'completed',
            'gateway' => 'manual',
        ]);

        $response = $this->actingAs($admin)->postJson("/vendas/{$order->id}/refund");

        $response->assertOk()->assertJsonPath('success', true);
        $order->refresh();
        $this->assertSame('refunded', $order->status);
        $this->assertFalse($product->users()->where('user_id', $buyer->id)->exists());
    }
}
