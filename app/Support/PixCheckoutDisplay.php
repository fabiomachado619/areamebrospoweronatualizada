<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Support\Str;

class PixCheckoutDisplay
{
    public const EXPIRY_SECONDS = 900;

    /**
     * @param  array<string, mixed>  $pixResult
     */
    public static function persistOnOrder(Order $order, array $pixResult): void
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $meta['pix_qrcode'] = $pixResult['qrcode'] ?? null;
        $meta['pix_copy_paste'] = $pixResult['copy_paste'] ?? null;
        $meta['pix_generated_at'] = time();
        $order->update(['metadata' => $meta]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function storeSession(Order $order, array $pixResult, array $context = []): string
    {
        $token = Str::random(32);
        $generatedAt = (int) ($context['created_at'] ?? time());

        session()->put('pix_display.'.$token, array_merge([
            'order_id' => $order->id,
            'qrcode' => $pixResult['qrcode'] ?? null,
            'copy_paste' => $pixResult['copy_paste'] ?? null,
            'amount' => (float) ($context['amount'] ?? $order->amount),
            'product_name' => $context['product_name'] ?? $order->product?->name,
            'checkout_slug' => $context['checkout_slug'] ?? $order->getCheckoutSlug(),
            'redirect_after_purchase' => $context['redirect_after_purchase'] ?? null,
            'customer_name' => $context['customer_name'] ?? null,
            'customer_email' => $context['customer_email'] ?? $order->email,
            'customer_phone' => $context['customer_phone'] ?? $order->phone,
            'created_at' => $generatedAt,
        ], $context));

        return $token;
    }

    /**
     * @param  array<string, mixed>  $pixResult
     * @param  array<string, mixed>  $context
     */
    public static function persistAndStoreSession(Order $order, array $pixResult, array $context = []): string
    {
        static::persistOnOrder($order, $pixResult);

        return static::storeSession($order, $pixResult, $context);
    }

    public static function isPixMetadataValid(array $metadata): bool
    {
        $generatedAt = (int) ($metadata['pix_generated_at'] ?? 0);
        if ($generatedAt <= 0) {
            return false;
        }
        if ($generatedAt + self::EXPIRY_SECONDS < time()) {
            return false;
        }

        $copyPaste = trim((string) ($metadata['pix_copy_paste'] ?? ''));
        $qrcode = trim((string) ($metadata['pix_qrcode'] ?? ''));

        return $copyPaste !== '' || $qrcode !== '';
    }

    /**
     * @return array{qrcode: ?string, copy_paste: ?string, created_at: int}|null
     */
    public static function pixDataFromOrder(Order $order): ?array
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        if (! static::isPixMetadataValid($meta)) {
            return null;
        }

        return [
            'qrcode' => $meta['pix_qrcode'] ?? null,
            'copy_paste' => $meta['pix_copy_paste'] ?? null,
            'created_at' => (int) $meta['pix_generated_at'],
        ];
    }
}
