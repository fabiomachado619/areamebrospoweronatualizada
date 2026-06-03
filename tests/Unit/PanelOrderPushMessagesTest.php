<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\Product;
use App\Support\PanelOrderPushMessages;
use App\Support\PanelPushPreferences;
use Tests\TestCase;

class PanelOrderPushMessagesTest extends TestCase
{
    public function test_sale_approved_message_uses_card_label_for_apple_pay(): void
    {
        $product = Product::make(['name' => 'Curso Teste']);
        $order = Order::make([
            'id' => 42,
            'amount' => 97.5,
            'currency' => 'BRL',
            'metadata' => ['checkout_payment_method' => 'apple_pay'],
        ]);
        $order->setRelation('product', $product);

        $message = PanelOrderPushMessages::forSaleApproved($order);

        $this->assertSame('card', $message['category']);
        $this->assertStringContainsString('Apple Pay', $message['body']);
        $this->assertStringContainsString('Curso Teste', $message['body']);
    }

    public function test_boleto_generated_message_category_is_boleto(): void
    {
        $product = Product::make(['name' => 'Ebook']);
        $order = Order::make([
            'amount' => 49.9,
            'currency' => 'BRL',
            'metadata' => ['checkout_payment_method' => 'boleto'],
        ]);
        $order->setRelation('product', $product);

        $message = PanelOrderPushMessages::forBoletoGenerated($order);

        $this->assertSame('boleto', $message['category']);
        $this->assertSame('Boleto gerado!', $message['title']);
    }

    public function test_push_preferences_default_all_enabled(): void
    {
        $prefs = PanelPushPreferences::normalize(null);

        $this->assertTrue($prefs['pix']);
        $this->assertTrue($prefs['boleto']);
        $this->assertTrue($prefs['card']);
        $this->assertTrue(PanelPushPreferences::allowsCategory($prefs, 'card'));
    }

    public function test_push_preferences_can_disable_card(): void
    {
        $prefs = PanelPushPreferences::normalize(['card' => false]);

        $this->assertFalse(PanelPushPreferences::allowsCategory($prefs, 'card'));
        $this->assertTrue(PanelPushPreferences::allowsCategory($prefs, 'pix'));
    }
}
