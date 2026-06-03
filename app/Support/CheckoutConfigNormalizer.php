<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Normaliza checkout_config: migra banners legacy para content_blocks e espelha arrays legacy ao salvar.
 */
class CheckoutConfigNormalizer
{
    public const IMAGE_FORMATS = ['hero', 'wide', 'portrait', 'square'];

    public const TEXT_ALIGNS = ['left', 'center', 'right'];

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function normalize(array $config): array
    {
        if (! isset($config['appearance']) || ! is_array($config['appearance'])) {
            $config['appearance'] = [];
        }

        $appearance = $config['appearance'];
        $blocks = self::normalizeContentBlocks($appearance);

        $appearance['content_blocks'] = $blocks;
        $appearance['banners'] = self::legacyBannerUrls($blocks, 'hero', 'main');
        $appearance['side_banners'] = self::legacyBannerUrls($blocks, 'portrait', 'sidebar');

        $config['appearance'] = $appearance;

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function prepareForStorage(array $config): array
    {
        $normalized = self::normalize($config);

        if (isset($normalized['appearance']['content_blocks']) && is_array($normalized['appearance']['content_blocks'])) {
            $normalized['appearance']['content_blocks'] = array_values(array_map(
                fn ($block) => self::sanitizeBlockForStorage($block),
                $normalized['appearance']['content_blocks']
            ));
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $appearance
     * @return list<array<string, mixed>>
     */
    private static function normalizeContentBlocks(array $appearance): array
    {
        $existing = $appearance['content_blocks'] ?? null;
        if (is_array($existing) && $existing !== []) {
            return array_values(array_filter(array_map(
                fn ($block) => self::normalizeBlock($block),
                $existing
            )));
        }

        $blocks = [];

        foreach ($appearance['banners'] ?? [] as $url) {
            if (! is_string($url) || trim($url) === '') {
                continue;
            }
            $blocks[] = self::makeImageBlock(trim($url), 'hero', 'main');
        }

        foreach ($appearance['side_banners'] ?? [] as $url) {
            if (! is_string($url) || trim($url) === '') {
                continue;
            }
            $blocks[] = self::makeImageBlock(trim($url), 'portrait', 'sidebar');
        }

        return $blocks;
    }

    /**
     * @param  mixed  $block
     * @return array<string, mixed>|null
     */
    private static function normalizeBlock(mixed $block): ?array
    {
        if (is_string($block) && trim($block) !== '') {
            return self::makeImageBlock(trim($block), 'hero', 'main');
        }

        if (! is_array($block)) {
            return null;
        }

        $type = (string) ($block['type'] ?? 'image');
        if ($type === 'text') {
            $title = trim((string) ($block['title'] ?? ''));
            $body = trim((string) ($block['body'] ?? ''));
            if ($title === '' && $body === '') {
                return null;
            }

            $align = (string) ($block['align'] ?? 'center');
            if (! in_array($align, self::TEXT_ALIGNS, true)) {
                $align = 'center';
            }

            return [
                'id' => self::blockId($block),
                'type' => 'text',
                'title' => $title,
                'body' => $body,
                'align' => $align,
            ];
        }

        $url = trim((string) ($block['url'] ?? ''));
        if ($url === '') {
            return null;
        }

        $format = (string) ($block['format'] ?? 'wide');
        if (! in_array($format, self::IMAGE_FORMATS, true)) {
            $format = 'wide';
        }

        $placement = (string) ($block['placement'] ?? ($format === 'portrait' ? 'sidebar' : 'main'));
        if (! in_array($placement, ['main', 'sidebar'], true)) {
            $placement = $format === 'portrait' ? 'sidebar' : 'main';
        }

        return [
            'id' => self::blockId($block),
            'type' => 'image',
            'url' => $url,
            'format' => $format,
            'placement' => $placement,
            'link' => trim((string) ($block['link'] ?? '')),
            'alt' => trim((string) ($block['alt'] ?? '')),
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private static function sanitizeBlockForStorage(array $block): array
    {
        if (($block['type'] ?? '') === 'text') {
            return [
                'id' => self::blockId($block),
                'type' => 'text',
                'title' => trim((string) ($block['title'] ?? '')),
                'body' => trim((string) ($block['body'] ?? '')),
                'align' => in_array($block['align'] ?? '', self::TEXT_ALIGNS, true)
                    ? $block['align']
                    : 'center',
            ];
        }

        $format = in_array($block['format'] ?? '', self::IMAGE_FORMATS, true)
            ? $block['format']
            : 'wide';

        return [
            'id' => self::blockId($block),
            'type' => 'image',
            'url' => trim((string) ($block['url'] ?? '')),
            'format' => $format,
            'placement' => ($block['placement'] ?? '') === 'sidebar' ? 'sidebar' : 'main',
            'link' => trim((string) ($block['link'] ?? '')),
            'alt' => trim((string) ($block['alt'] ?? '')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function makeImageBlock(string $url, string $format, string $placement): array
    {
        return [
            'id' => (string) Str::uuid(),
            'type' => 'image',
            'url' => $url,
            'format' => $format,
            'placement' => $placement,
            'link' => '',
            'alt' => '',
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private static function blockId(array $block): string
    {
        $id = trim((string) ($block['id'] ?? ''));

        return $id !== '' ? $id : (string) Str::uuid();
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<string>
     */
    private static function legacyBannerUrls(array $blocks, string $format, string $placement): array
    {
        $urls = [];
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') !== 'image') {
                continue;
            }
            if (($block['format'] ?? '') !== $format) {
                continue;
            }
            if (($block['placement'] ?? 'main') !== $placement) {
                continue;
            }
            $url = trim((string) ($block['url'] ?? ''));
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }
}
