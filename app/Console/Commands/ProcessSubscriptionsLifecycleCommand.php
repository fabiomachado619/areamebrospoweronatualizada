<?php

namespace App\Console\Commands;

use App\Services\SubscriptionLifecycleService;
use Illuminate\Console\Command;

class ProcessSubscriptionsLifecycleCommand extends Command
{
    protected $signature = 'subscriptions:process-lifecycle';

    protected $description = 'Process subscription statuses, access revocation, and past_due/cancelled transitions';

    public function handle(SubscriptionLifecycleService $lifecycle): int
    {
        $lifecycle->processDaily();
        $this->info('Subscription lifecycle processed.');

        return self::SUCCESS;
    }
}
