<?php

namespace App\Services;

use App\Events\SubscriptionCancelled;
use App\Events\SubscriptionPastDue;
use App\Models\Product;
use App\Models\Subscription;
use Carbon\Carbon;

class SubscriptionLifecycleService
{

    /**
     * @return array{grace_period_days: int, notify_days_before: int, renewal_window_days: int}
     */
    public function settingsFor(Subscription $subscription): array
    {
        $product = $subscription->relationLoaded('product')
            ? $subscription->product
            : $subscription->product()->first();

        if (! $product || $product->billing_type !== Product::BILLING_SUBSCRIPTION) {
            return Product::defaultSubscriptionSettings();
        }

        return $product->subscriptionSettings();
    }

    public function periodEnd(Subscription $subscription): ?Carbon
    {
        if ($subscription->current_period_end === null) {
            return null;
        }

        return Carbon::parse($subscription->current_period_end)->startOfDay();
    }

    public function accessUntil(Subscription $subscription): ?Carbon
    {
        $end = $this->periodEnd($subscription);
        if ($end === null) {
            return null;
        }

        $settings = $this->settingsFor($subscription);

        return $end->copy()->addDays($settings['grace_period_days']);
    }

    public function renewableUntil(Subscription $subscription): ?Carbon
    {
        $end = $this->periodEnd($subscription);
        if ($end === null) {
            return null;
        }

        $settings = $this->settingsFor($subscription);

        return $end->copy()->addDays($settings['renewal_window_days']);
    }

    public function isLifetime(Subscription $subscription): bool
    {
        $plan = $subscription->relationLoaded('subscriptionPlan')
            ? $subscription->subscriptionPlan
            : $subscription->subscriptionPlan()->first();

        return $plan && $plan->isLifetime();
    }

    public function canRenew(Subscription $subscription, ?Carbon $today = null): bool
    {
        if ($subscription->status === Subscription::STATUS_CANCELLED) {
            return false;
        }
        if ($this->isLifetime($subscription)) {
            return false;
        }

        $today = ($today ?? Carbon::today())->startOfDay();
        $renewableUntil = $this->renewableUntil($subscription);
        if ($renewableUntil === null) {
            return false;
        }

        return $today->lte($renewableUntil);
    }

    public function hasAccess(Subscription $subscription, ?Carbon $today = null): bool
    {
        if ($this->isLifetime($subscription)) {
            return true;
        }

        $today = ($today ?? Carbon::today())->startOfDay();
        $accessUntil = $this->accessUntil($subscription);

        if ($subscription->status === Subscription::STATUS_CANCELLED) {
            return $accessUntil !== null && $today->lte($accessUntil);
        }

        if ($accessUntil === null) {
            return $subscription->status === Subscription::STATUS_ACTIVE;
        }

        return $today->lte($accessUntil);
    }

    /**
     * Status efetivo para exibição (pode diferir do DB após processamento).
     */
    public function effectiveStatus(Subscription $subscription, ?Carbon $today = null): string
    {
        if ($subscription->status === Subscription::STATUS_CANCELLED) {
            return Subscription::STATUS_CANCELLED;
        }

        $today = ($today ?? Carbon::today())->startOfDay();
        $periodEnd = $this->periodEnd($subscription);

        if ($periodEnd === null || $this->isLifetime($subscription)) {
            return Subscription::STATUS_ACTIVE;
        }

        if ($today->lte($periodEnd)) {
            return Subscription::STATUS_ACTIVE;
        }

        $accessUntil = $this->accessUntil($subscription);
        if ($accessUntil && $today->lte($accessUntil)) {
            return Subscription::STATUS_ACTIVE;
        }

        $renewableUntil = $this->renewableUntil($subscription);
        if ($renewableUntil && $today->lte($renewableUntil)) {
            return Subscription::STATUS_PAST_DUE;
        }

        return Subscription::STATUS_CANCELLED;
    }

    public function shouldSendReminderToday(Subscription $subscription, ?Carbon $today = null): bool
    {
        if (in_array($subscription->status, [Subscription::STATUS_CANCELLED], true)) {
            return false;
        }
        if ($this->isLifetime($subscription)) {
            return false;
        }

        $today = ($today ?? Carbon::today())->startOfDay();
        if ($subscription->last_reminder_sent_on && Carbon::parse($subscription->last_reminder_sent_on)->isSameDay($today)) {
            return false;
        }

        $periodEnd = $this->periodEnd($subscription);
        if ($periodEnd === null) {
            return false;
        }

        $settings = $this->settingsFor($subscription);
        $notifyStart = $periodEnd->copy()->subDays($settings['notify_days_before']);
        $renewableUntil = $this->renewableUntil($subscription);

        if ($renewableUntil === null) {
            return false;
        }

        return $today->gte($notifyStart) && $today->lte($renewableUntil);
    }

    /**
     * @return 'before_due'|'grace'|'past_due'|null
     */
    public function reminderPhase(Subscription $subscription, ?Carbon $today = null): ?string
    {
        $today = ($today ?? Carbon::today())->startOfDay();
        $periodEnd = $this->periodEnd($subscription);
        if ($periodEnd === null) {
            return null;
        }

        if ($today->lte($periodEnd)) {
            return 'before_due';
        }

        $accessUntil = $this->accessUntil($subscription);
        if ($accessUntil && $today->lte($accessUntil)) {
            return 'grace';
        }

        return 'past_due';
    }

    public function daysUntilPeriodEnd(Subscription $subscription, ?Carbon $today = null): ?int
    {
        $periodEnd = $this->periodEnd($subscription);
        if ($periodEnd === null) {
            return null;
        }

        $today = ($today ?? Carbon::today())->startOfDay();

        return (int) $today->diffInDays($periodEnd, false);
    }

    public function processDaily(?Carbon $today = null): void
    {
        $today = ($today ?? Carbon::today())->startOfDay();

        Subscription::query()
            ->with(['user', 'product', 'subscriptionPlan'])
            ->whereNotNull('current_period_end')
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE])
            ->chunkById(100, function ($subscriptions) use ($today) {
                foreach ($subscriptions as $subscription) {
                    $this->processSubscription($subscription, $today);
                }
            });
    }

    public function processSubscription(Subscription $subscription, ?Carbon $today = null): void
    {
        $today = ($today ?? Carbon::today())->startOfDay();

        if ($this->isLifetime($subscription)) {
            return;
        }

        $effective = $this->effectiveStatus($subscription, $today);

        if ($effective === Subscription::STATUS_CANCELLED
            && $subscription->status !== Subscription::STATUS_CANCELLED) {
            $this->cancelSubscription($subscription, expired: true);

            return;
        }

        if ($effective === Subscription::STATUS_PAST_DUE
            && $subscription->status === Subscription::STATUS_ACTIVE) {
            $this->markPastDue($subscription);
        }

        if ($effective === Subscription::STATUS_ACTIVE
            && $subscription->status === Subscription::STATUS_PAST_DUE) {
            $subscription->update(['status' => Subscription::STATUS_ACTIVE, 'past_due_at' => null]);
        }

        if (! $this->hasAccess($subscription, $today)) {
            app(SubscriptionAccessService::class)->revokeAccessForSubscription($subscription);
        }
    }

    public function markPastDue(Subscription $subscription): void
    {
        if ($subscription->status === Subscription::STATUS_PAST_DUE) {
            return;
        }

        $wasActive = $subscription->status === Subscription::STATUS_ACTIVE;
        $subscription->update([
            'status' => Subscription::STATUS_PAST_DUE,
            'past_due_at' => $subscription->past_due_at ?? now(),
        ]);

        if ($wasActive) {
            event(new SubscriptionPastDue($subscription->fresh(['user', 'product', 'subscriptionPlan'])));
        }
    }

    public function cancelSubscription(Subscription $subscription, bool $revokeAccessNow = false, bool $expired = false): void
    {
        if ($subscription->status === Subscription::STATUS_CANCELLED) {
            return;
        }

        $subscription->update([
            'status' => Subscription::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);

        if ($revokeAccessNow || $expired) {
            app(SubscriptionAccessService::class)->revokeAccessForSubscription($subscription);
        }

        event(new SubscriptionCancelled($subscription->fresh(['user', 'product', 'subscriptionPlan'])));
    }

    public function markReminderSent(Subscription $subscription, ?Carbon $today = null): void
    {
        $today = ($today ?? Carbon::today())->startOfDay();
        $subscription->update(['last_reminder_sent_on' => $today->toDateString()]);
    }

    /**
     * @return array{subject: string, body: string}
     */
    public function buildReminderEmail(Subscription $subscription, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->startOfDay();
        $user = $subscription->user;
        $product = $subscription->product;
        $plan = $subscription->subscriptionPlan;
        $renewalUrl = url('/renovar/'.$subscription->renewal_token);
        $productName = e($product?->name ?? 'produto');
        $planName = e($plan?->name ?? 'plano');
        $greeting = $user?->name ? ', '.e($user->name) : '';

        $phase = $this->reminderPhase($subscription, $today);
        $daysLeft = $this->daysUntilPeriodEnd($subscription, $today);

        if ($phase === 'before_due' && $daysLeft !== null && $daysLeft > 0) {
            $subject = 'Lembrete: sua assinatura de '.($product?->name ?? 'produto').' renova em '.$daysLeft.' dia(s)';
            $body = '<p>Olá'.$greeting.'!</p>';
            $body .= '<p>Sua assinatura de <strong>'.$productName.'</strong> (plano '.$planName.') renova em <strong>'.$daysLeft.' dia(s)</strong>.</p>';
        } elseif ($phase === 'grace') {
            $accessUntil = $this->accessUntil($subscription);
            $subject = 'Sua assinatura de '.($product?->name ?? 'produto').' venceu — renove para continuar';
            $body = '<p>Olá'.$greeting.'!</p>';
            $body .= '<p>Sua assinatura de <strong>'.$productName.'</strong> (plano '.$planName.') <strong>venceu</strong>, mas você ainda tem acesso';
            if ($accessUntil) {
                $body .= ' até <strong>'.$accessUntil->format('d/m/Y').'</strong>';
            }
            $body .= '.</p>';
        } else {
            $renewableUntil = $this->renewableUntil($subscription);
            $subject = 'Renove sua assinatura de '.($product?->name ?? 'produto');
            $body = '<p>Olá'.$greeting.'!</p>';
            $body .= '<p>Sua assinatura de <strong>'.$productName.'</strong> (plano '.$planName.') está <strong>em atraso</strong>.</p>';
            if ($renewableUntil) {
                $body .= '<p>Você ainda pode renovar até <strong>'.$renewableUntil->format('d/m/Y').'</strong>.</p>';
            }
        }

        $body .= '<p>Para renovar e manter seu acesso, use o link abaixo:</p>';
        $body .= '<p><a href="'.e($renewalUrl).'" style="display:inline-block;padding:12px 24px;background:#0ea5e9;color:#fff;text-decoration:none;border-radius:8px;">Renovar agora</a></p>';
        $body .= '<p>Ou copie e cole no navegador: '.e($renewalUrl).'</p>';

        return ['subject' => $subject, 'body' => $body];
    }
}
