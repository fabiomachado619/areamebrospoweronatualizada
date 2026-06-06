<?php

namespace App\Http\Middleware;

use App\Services\MemberAreaResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMemberAreaAccess
{
    /**
     * Require auth and that the user has access to the member area product.
     *
     * @param  \Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            $accessType = $request->attributes->get('member_area_access_type');
            $resolver = app(MemberAreaResolver::class);
            if ($resolver->usesHostLoginPath(is_string($accessType) ? $accessType : null)) {
                $product = $request->attributes->get('member_area_product');
                $loginPath = $product instanceof \App\Models\Product
                    ? $resolver->memberAreaLoginPath($request, $product)
                    : ($resolver->hubMainHostLoginPath() ?? '/login');

                return redirect()->to($loginPath)->with('error', 'Faça login para acessar a área de membros.');
            }

            $slug = $request->route('slug') ?? $request->attributes->get('member_area_slug');
            if ($slug) {
                return redirect()->route('member-area.login', ['slug' => $slug])
                    ->with('error', 'Faça login para acessar a área de membros.');
            }

            return redirect()->route('login')->with('error', 'Faça login para acessar.');
        }

        $product = $request->route('product') ?? $request->attributes->get('member_area_product');
        if (! $product) {
            abort(404, 'Área de membros não encontrada.');
        }

        if (! $product->hasMemberAreaAccess($request->user())) {
            return redirect()->route('checkout.show', ['slug' => $product->checkout_slug])
                ->with('error', 'Você não tem acesso a esta área. Adquira o produto para continuar.');
        }

        return $next($request);
    }
}
