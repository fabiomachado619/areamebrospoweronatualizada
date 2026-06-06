<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class BrandFavicon
{
    public static function configuredUrl(): ?string
    {
        $configured = config('getfy.favicon_url');
        if (! is_string($configured)) {
            return null;
        }

        $configured = trim($configured);

        return $configured !== '' ? $configured : null;
    }

    public static function isExternalUrl(string $url): bool
    {
        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
    }

    public static function sourcePath(): ?string
    {
        $configured = self::configuredUrl();
        if ($configured === null) {
            return self::defaultPublicPath();
        }

        if (self::isExternalUrl($configured)) {
            return self::cachedRemotePath($configured);
        }

        if (str_starts_with($configured, '/storage/')) {
            $relative = ltrim(substr($configured, strlen('/storage/')), '/');
            $paths = [
                storage_path('app/public/'.$relative),
                public_path('storage/'.$relative),
            ];
            foreach ($paths as $path) {
                if (is_file($path)) {
                    return $path;
                }
            }

            return null;
        }

        if (str_starts_with($configured, '/')) {
            $path = public_path(ltrim($configured, '/'));

            return is_file($path) ? $path : null;
        }

        return null;
    }

    public static function optimizedPath(): ?string
    {
        $source = self::sourcePath();
        if ($source === null) {
            return null;
        }

        if (! str_ends_with(strtolower($source), '.png')) {
            return $source;
        }

        $cacheDir = storage_path('app/cache');
        if (! is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        $cache = $cacheDir.DIRECTORY_SEPARATOR.'brand-favicon-'.md5($source).'-32.png';
        if (is_file($cache) && filemtime($cache) >= filemtime($source)) {
            return $cache;
        }

        if (! extension_loaded('gd')) {
            return $source;
        }

        $image = @imagecreatefrompng($source);
        if ($image === false) {
            return $source;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width < 1 || $height < 1) {
            imagedestroy($image);

            return $source;
        }

        $size = 32;
        $canvas = imagecreatetruecolor($size, $size);
        if ($canvas === false) {
            imagedestroy($image);

            return $source;
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $size, $size, $transparent);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $size, $size, $width, $height);
        imagepng($canvas, $cache, 6);
        imagedestroy($image);
        imagedestroy($canvas);

        return is_file($cache) ? $cache : $source;
    }

    public static function publicUrl(): string
    {
        $configured = self::configuredUrl();
        if ($configured !== null && self::isExternalUrl($configured)) {
            return $configured;
        }

        $source = self::sourcePath();
        if ($source !== null) {
            return route('brand.favicon', ['v' => filemtime($source)]);
        }

        $default = self::defaultPublicPath();
        if ($default !== null) {
            return route('brand.favicon', ['v' => filemtime($default)]);
        }

        return url('/brand/favicon-32x32.png');
    }

    public static function serve(): SymfonyResponse
    {
        $configured = self::configuredUrl();
        if ($configured !== null && self::isExternalUrl($configured)) {
            $cached = self::cachedRemotePath($configured);
            if ($cached !== null && is_file($cached)) {
                return response()->file($cached, [
                    'Content-Type' => 'image/png',
                    'Cache-Control' => 'public, max-age=86400',
                ]);
            }

            return redirect($configured, 302);
        }

        $path = self::optimizedPath() ?? self::sourcePath() ?? self::defaultPublicPath();
        if ($path === null) {
            return redirect(url('/brand/favicon-32x32.png'), 302);
        }

        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'ico' => 'image/x-icon',
            'svg' => 'image/svg+xml',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };

        return response()->file($path, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    private static function defaultPublicPath(): ?string
    {
        $path = public_path('brand/favicon.png');

        return is_file($path) ? $path : null;
    }

    private static function cachedRemotePath(string $url): ?string
    {
        $cacheDir = storage_path('app/cache');
        if (! is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        $cache = $cacheDir.DIRECTORY_SEPARATOR.'brand-favicon-remote-'.md5($url);
        if (is_file($cache) && filesize($cache) > 0) {
            return $cache;
        }

        try {
            $response = Http::timeout(8)->get($url);
            if (! $response->successful()) {
                return null;
            }
            $body = $response->body();
            if ($body === '') {
                return null;
            }
            file_put_contents($cache, $body);

            return is_file($cache) ? $cache : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
