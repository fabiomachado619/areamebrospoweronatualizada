<?php

namespace App\Jobs;

use App\Mail\SubscriptionReminderMail;
use App\Models\Subscription;
use App\Services\SubscriptionLifecycleService;
use App\Services\TenantMailConfigService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendSubscriptionRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(
        TenantMailConfigService $mailConfig,
        SubscriptionLifecycleService $lifecycle,
    ): void {
        $today = Carbon::today();

        $lifecycle->processDaily($today);

        Subscription::query()
            ->with(['user', 'product', 'subscriptionPlan'])
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE])
            ->whereNotNull('current_period_end')
            ->chunkById(100, function ($subscriptions) use ($mailConfig, $lifecycle, $today) {
                foreach ($subscriptions as $subscription) {
                    if (! $lifecycle->shouldSendReminderToday($subscription, $today)) {
                        continue;
                    }

                    $user = $subscription->user;
                    if (! $user || ! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                        continue;
                    }

                    $email = $lifecycle->buildReminderEmail($subscription, $today);

                    try {
                        $mailConfig->applyMailerConfigForTenant($subscription->tenant_id, [], null);
                        Mail::mailer('smtp')->to($user->email)->send(
                            new SubscriptionReminderMail($email['subject'], $email['body'])
                        );
                        $lifecycle->markReminderSent($subscription, $today);
                    } catch (\Throwable $e) {
                        Log::warning('SendSubscriptionRemindersJob: falha ao enviar lembrete.', [
                            'subscription_id' => $subscription->id,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }
}
