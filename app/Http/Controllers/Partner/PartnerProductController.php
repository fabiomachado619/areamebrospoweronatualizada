<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\SubscriptionPlan;
use App\Services\PartnerAccessService;
use App\Services\StorageService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PartnerProductController extends Controller
{
    public function show(Request $request, Product $produto): Response
    {
        $user = $request->user();
        $access = app(PartnerAccessService::class);

        if (! $access->canAccessProduct($user, $produto)) {
            abort(403);
        }

        $type = $access->partnerTypeForProduct($user, $produto->id);
        $affiliate = $access->affiliateMembershipForProduct($user, $produto->id);
        $links = [];
        $canUseLinks = $user->isAfiliado() && $access->canUseAffiliateLinks($user, $produto->id);
        $canEditPixels = $user->isAfiliado() && $access->canEditAffiliatePixels($user, $produto->id);

        if ($canUseLinks && $affiliate) {
            $ref = $affiliate->affiliate_code;
            $links[] = [
                'label' => 'Checkout principal',
                'url' => url('/c/'.$produto->checkout_slug.'?ref='.$ref),
            ];

            foreach (ProductOffer::where('product_id', $produto->id)->whereNotNull('checkout_slug')->orderBy('name')->get() as $offer) {
                $links[] = [
                    'label' => 'Oferta: '.$offer->name,
                    'url' => url('/c/'.$offer->checkout_slug.'?ref='.$ref),
                ];
            }

            foreach (ProductOffer::where('product_id', $produto->id)->whereNull('checkout_slug')->orderBy('name')->get() as $offer) {
                $publicId = $offer->ensurePublicId();
                $query = $publicId ? 'offer='.$publicId : 'offer_id='.$offer->id;
                $links[] = [
                    'label' => 'Oferta: '.$offer->name,
                    'url' => url('/c/'.$produto->checkout_slug.'?'.$query.'&ref='.$ref),
                ];
            }

            foreach (SubscriptionPlan::where('product_id', $produto->id)->whereNotNull('checkout_slug')->orderBy('name')->get() as $plan) {
                $links[] = [
                    'label' => 'Plano: '.$plan->name,
                    'url' => url('/c/'.$plan->checkout_slug.'?ref='.$ref),
                ];
            }

            foreach (SubscriptionPlan::where('product_id', $produto->id)->whereNull('checkout_slug')->orderBy('name')->get() as $plan) {
                $publicId = $plan->ensurePublicId();
                $query = $publicId ? 'plan='.$publicId : 'plan_id='.$plan->id;
                $links[] = [
                    'label' => 'Plano: '.$plan->name,
                    'url' => url('/c/'.$produto->checkout_slug.'?'.$query.'&ref='.$ref),
                ];
            }
        }

        $commissionPercent = null;
        if ($affiliate) {
            $commissionPercent = $access->commissionPercentForAffiliate($affiliate, $produto);
        } elseif ($type === 'coprodutor') {
            $coprod = \App\Models\ProductCoproducer::query()
                ->where('product_id', $produto->id)
                ->where('user_id', $user->id)
                ->where('status', \App\Models\ProductCoproducer::STATUS_ACTIVE)
                ->first();
            $commissionPercent = $coprod ? (float) $coprod->commission_percent : null;
        }

        return Inertia::render('Partner/ProductShow', [
            'produto' => [
                'id' => $produto->id,
                'name' => $produto->name,
                'description' => $produto->description,
                'type' => $produto->type,
                'checkout_slug' => $produto->checkout_slug,
                'price' => (float) $produto->price,
                'currency' => $produto->currency ?? 'BRL',
                'image_url' => $produto->image ? app(StorageService::class)->url($produto->image) : null,
            ],
            'partner_type' => $type,
            'affiliate_status' => $affiliate?->status,
            'commission_percent' => $commissionPercent,
            'affiliate' => $affiliate ? [
                'affiliate_code' => $affiliate->affiliate_code,
                'affiliate_pixels' => $affiliate->affiliate_pixels ?? [],
            ] : null,
            'links' => $links,
            'can_use_links' => $canUseLinks,
            'can_edit_pixels' => $canEditPixels,
            'tab' => $request->query('tab', 'overview'),
        ]);
    }

    public function updatePixels(Request $request, Product $produto)
    {
        $user = $request->user();
        $access = app(PartnerAccessService::class);

        if (! $user->isAfiliado() || ! $access->canEditAffiliatePixels($user, $produto->id)) {
            abort(403);
        }

        $affiliate = $access->affiliateMembershipForProduct($user, $produto->id);
        if (! $affiliate || $affiliate->status !== \App\Models\ProductAffiliate::STATUS_APPROVED) {
            abort(403);
        }

        $validated = $request->validate([
            'affiliate_pixels' => ['nullable', 'array'],
        ]);

        $affiliate->update(['affiliate_pixels' => $validated['affiliate_pixels'] ?? []]);

        return back()->with('success', 'Pixels atualizados.');
    }
}
