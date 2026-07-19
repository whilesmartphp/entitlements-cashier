<?php

namespace Whilesmart\EntitlementsCashier\Console;

use Illuminate\Console\Command;
use Whilesmart\EntitlementsCashier\PlanPriceSync;

class SyncPricesCommand extends Command
{
    protected $signature = 'entitlements-cashier:sync-prices';

    protected $description = 'Ensure each priced plan has a Stripe Price matching its amount, recording the id.';

    public function handle(PlanPriceSync $sync): int
    {
        $sync->run();

        $this->info('Plan prices synced with Stripe.');

        return self::SUCCESS;
    }
}
