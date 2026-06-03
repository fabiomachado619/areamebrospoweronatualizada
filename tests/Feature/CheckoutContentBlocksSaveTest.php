<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Tests\TestCase;

class CheckoutContentBlocksSaveTest extends TestCase
{
    public function test_checkout_config_persists_content_blocks_and_legacy_banners(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct();

        $blocks = [
            [
                'id' => 'hero-1',
                'type' => 'image',
                'url' => '/storage/checkout/hero.jpg',
                'format' => 'hero',
                'placement' => 'main',
                'link' => '',
                'alt' => '',
            ],
            [
                'id' => 'text-1',
                'type' => 'text',
                'title' => 'Oferta especial',
                'body' => 'Descrição da oferta',
                'align' => 'center',
            ],
        ];

        $response = $this->actingAs($user)->put("/produtos/{$product->id}/checkout-config", [
            'offer_id' => null,
            'plan_id' => null,
            'config' => [
                'appearance' => [
                    'content_blocks' => $blocks,
                    'banners' => [],
                    'side_banners' => [],
                ],
            ],
        ]);

        $response->assertRedirect();
        $product->refresh();
        $stored = $product->checkout_config['appearance'] ?? [];

        $this->assertCount(2, $stored['content_blocks'] ?? []);
        $this->assertSame('Oferta especial', $stored['content_blocks'][1]['title'] ?? null);
        $this->assertSame(['/storage/checkout/hero.jpg'], $stored['banners'] ?? null);
    }
}
