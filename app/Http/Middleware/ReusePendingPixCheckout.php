<?php

namespace App\Http\Middleware;

use App\Support\PendingPixCheckoutResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReusePendingPixCheckout
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST')) {
            return $next($request);
        }

        if (! PendingPixCheckoutResolver::isPixLikePaymentMethod($request->input('payment_method'))) {
            return $next($request);
        }

        $order = PendingPixCheckoutResolver::findReusable($request);
        if ($order) {
            return PendingPixCheckoutResolver::redirectToPixPage($order, $request);
        }

        return $next($request);
    }
}
