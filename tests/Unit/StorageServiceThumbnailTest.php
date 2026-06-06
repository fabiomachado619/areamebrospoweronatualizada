<?php

namespace Tests\Unit;

use App\Services\StorageService;
use Tests\TestCase;

class StorageServiceThumbnailTest extends TestCase
{
    public function test_resolve_public_url_from_relative_path(): void
    {
        $storage = new StorageService(1);

        $this->assertSame('/storage/member-area/abc.jpg', $storage->resolvePublicUrl('member-area/abc.jpg'));
    }

    public function test_resolve_public_url_from_storage_prefixed_path(): void
    {
        $storage = new StorageService(1);

        $this->assertSame('/storage/member-area/abc.jpg', $storage->resolvePublicUrl('/storage/member-area/abc.jpg'));
    }

    public function test_normalize_stored_path_from_public_url(): void
    {
        $storage = new StorageService(1);
        $appUrl = rtrim(config('app.url'), '/');

        $this->assertSame(
            'member-area/abc.jpg',
            $storage->normalizeStoredPath($appUrl.'/storage/member-area/abc.jpg')
        );
    }

    public function test_resolve_public_url_returns_null_for_empty(): void
    {
        $storage = new StorageService(1);

        $this->assertNull($storage->resolvePublicUrl(null));
        $this->assertNull($storage->resolvePublicUrl(''));
    }
}
