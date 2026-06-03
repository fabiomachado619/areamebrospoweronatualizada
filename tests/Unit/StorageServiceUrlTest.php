<?php

namespace Tests\Unit;

use App\Services\StorageService;
use Tests\TestCase;

class StorageServiceUrlTest extends TestCase
{
    public function test_local_disk_url_is_relative(): void
    {
        $service = new StorageService(null);

        $this->assertSame('/storage/products/cover.jpg', $service->url('products/cover.jpg'));
        $this->assertSame('/storage/products/cover.jpg', $service->url('/products/cover.jpg'));
    }

    public function test_absolute_url_builds_from_app_url(): void
    {
        config(['app.url' => 'https://loja.test']);

        $service = new StorageService(null);

        $this->assertSame(
            'https://loja.test/storage/products/cover.jpg',
            $service->absoluteUrl('products/cover.jpg')
        );
    }

    public function test_empty_path_returns_empty_string(): void
    {
        $service = new StorageService(null);

        $this->assertSame('', $service->url(''));
        $this->assertSame('', $service->absoluteUrl(''));
    }
}
