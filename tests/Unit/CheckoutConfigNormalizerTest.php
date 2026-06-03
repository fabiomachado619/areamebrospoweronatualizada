<?php

namespace Tests\Unit;

use App\Support\CheckoutConfigNormalizer;
use Tests\TestCase;

class CheckoutConfigNormalizerTest extends TestCase
{
    public function test_migrates_legacy_banners_to_content_blocks(): void
    {
        $config = [
            'appearance' => [
                'banners' => ['/storage/hero.jpg'],
                'side_banners' => ['/storage/side.jpg'],
            ],
        ];

        $normalized = CheckoutConfigNormalizer::normalize($config);

        $blocks = $normalized['appearance']['content_blocks'];
        $this->assertCount(2, $blocks);
        $this->assertSame('image', $blocks[0]['type']);
        $this->assertSame('hero', $blocks[0]['format']);
        $this->assertSame('/storage/hero.jpg', $blocks[0]['url']);
        $this->assertSame('portrait', $blocks[1]['format']);
        $this->assertSame('sidebar', $blocks[1]['placement']);
        $this->assertSame(['/storage/hero.jpg'], $normalized['appearance']['banners']);
        $this->assertSame(['/storage/side.jpg'], $normalized['appearance']['side_banners']);
    }

    public function test_prepare_for_storage_keeps_text_blocks(): void
    {
        $config = CheckoutConfigNormalizer::normalize([
            'appearance' => [
                'content_blocks' => [
                    [
                        'id' => 'txt-1',
                        'type' => 'text',
                        'title' => 'Título',
                        'body' => 'Corpo',
                        'align' => 'center',
                    ],
                    [
                        'id' => 'img-1',
                        'type' => 'image',
                        'url' => '/storage/wide.jpg',
                        'format' => 'wide',
                        'placement' => 'main',
                        'link' => '',
                        'alt' => '',
                    ],
                ],
            ],
        ]);

        $stored = CheckoutConfigNormalizer::prepareForStorage($config);
        $blocks = $stored['appearance']['content_blocks'];

        $this->assertCount(2, $blocks);
        $this->assertSame('text', $blocks[0]['type']);
        $this->assertSame('Título', $blocks[0]['title']);
        $this->assertSame('wide', $blocks[1]['format']);
        $this->assertSame([], $stored['appearance']['banners']);
        $this->assertSame([], $stored['appearance']['side_banners']);
    }

    public function test_empty_legacy_arrays_produce_empty_blocks(): void
    {
        $config = CheckoutConfigNormalizer::normalize([
            'appearance' => [
                'banners' => [],
                'side_banners' => [],
            ],
        ]);

        $this->assertSame([], $config['appearance']['content_blocks']);
    }
}
