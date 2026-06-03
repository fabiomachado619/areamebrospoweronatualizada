<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PendingPixCheckoutResolver
{
    public static function isPixLikePaymentMethod(?string $method): bool
    {
        $method = strtolower(trim((string) $method));

        return in_array($method, ['pix', 'pix_auto'], true);
    }

    public static function findReusable(Request $request): ?Order
    {
        if (! static::isPixLikePaymentMethod($request->input('payment_method'))) {
            return null;
        }

        $email = static::normalizeEmail($request->input('email'));
        $productId = trim((string) $request->input('product_id', ''));
        if ($email === '' || $productId === '') {
            return null;
        }

        if (! Product::query()->where('id', $productId)->where('is_active', true)->exists()) {
            return null;
        }

        $since = now()->subSeconds(PixCheckoutDisplay::EXPIRY_SECONDS);

        $candidates = Order::query()
            ->with('product')
            ->where('status', 'pending')
            ->where('email', $email)
            ->where('product_id', $productId)
            ->whereNotNull('gateway_id')
            ->where('created_at', '>=', $since)
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $offerId = $request->filled('product_offer_id') ? (int) $request->input('product_offer_id') : null;
        $planId = $request->filled('subscription_plan_id') ? (int) $request->input('subscription_plan_id') : null;

        foreach ($candidates as $order) {
            if ($offerId !== null && (int) ($order->product_offer_id ?? 0) !== $offerId) {
                continue;
            }
            if ($planId !== null && (int) ($order->subscription_plan_id ?? 0) !== $planId) {
                continue;
            }
            if ($offerId === null && $order->product_offer_id !== null) {
                continue;
            }
            if ($planId === null && $order->subscription_plan_id !== null) {
                continue;
            }

            $meta = is_array($order->metadata) ? $order->metadata : [];
            $method = $meta['checkout_payment_method'] ?? null;
            if (! in_array($method, ['pix', 'pix_auto'], true)) {
                continue;
            }
            if (! PixCheckoutDisplay::isPixMetadataValid($meta)) {
                continue;
            }

            return $order;
        }

        return null;
    }

    public static function redirectToPixPage(Order $order, Request $request): RedirectResponse|JsonResponse
    {
        $order->loadMissing('product');
        $pixData = PixCheckoutDisplay::pixDataFromOrder($order);
        if ($pixData === null) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Código PIX expirado. Gere um novo PIX.'], 410);
            }

            $slug = $order->getCheckoutSlug();

            return $slug
                ? redirect()->route('checkout.show', ['slug' => $slug])->with('error', 'Código PIX expirado. Gere um novo PIX.')
                : back()->with('error', 'Código PIX expirado. Gere um novo PIX.');
        }

        $product = $order->product;
        $redirectAfterPurchase = $product?->checkout_config['redirect_after_purchase'] ?? null;
        $redirectAfterPurchase = is_string($redirectAfterPurchase) && trim($redirectAfterPurchase) !== ''
            ? $redirectAfterPurchase
            : null;

        $token = PixCheckoutDisplay::storeSession($order, $pixData, [
            'amount' => (float) $order->amount,
            'product_name' => $product?->name,
            'checkout_slug' => $order->getCheckoutSlug(),
            'redirect_after_purchase' => $redirectAfterPurchase,
            'customer_email' => $order->email,
            'customer_phone' => $order->phone,
            'created_at' => $pixData['created_at'],
        ]);

        $url = route('checkout.pix', ['token' => $token]);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'reused' => true,
                'redirect_url' => $url,
                'order_id' => $order->id,
            ]);
        }

        return redirect()->to($url);
    }

    private static function normalizeEmail(mixed $email): string
    {
        if (! is_string($email)) {
            return '';
        }

        return strtolower(trim($email));
    }
}
