<?php

namespace Plugins\AutoZap;

use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AutoZapEventUtils
{
    /**
     * @return array{0:?int,1:?string,2:array<string, mixed>} tenantId, productId, entityRefs
     */
    public static function resolveContext(object $event): array
    {
        $tenantId = null;
        $productId = null;
        $refs = [];

        foreach ((array) $event as $value) {
            if ($value instanceof Model) {
                $tid = $value->getAttribute('tenant_id');
                if ($tenantId === null && $tid !== null) {
                    $tenantId = (int) $tid;
                }
                if ($value instanceof Order) {
                    $productId = $productId ?? (string) ($value->product_id ?? '');
                    $refs['order_id'] = $value->id;
                } elseif ($value instanceof Product) {
                    $productId = $productId ?? (string) ($value->id ?? '');
                    $refs['product_id'] = $value->id;
                } elseif ($value instanceof User) {
                    $refs['user_id'] = $value->id;
                } elseif ($value instanceof CheckoutSession) {
                    $productId = $productId ?? (string) ($value->product_id ?? '');
                    $refs['checkout_session_id'] = $value->id;
                } elseif ($value instanceof Subscription) {
                    $productId = $productId ?? (string) ($value->product_id ?? '');
                    $refs['subscription_id'] = $value->id;
                }
            }
        }

        return [$tenantId, $productId, $refs];
    }
}

