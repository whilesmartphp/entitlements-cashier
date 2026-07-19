<?php

namespace Whilesmart\EntitlementsCashier\Listeners;

use Laravel\Cashier\Events\WebhookReceived;
use Whilesmart\Entitlements\Contracts\BillingProvider;

/**
 * Cashier keeps its own subscription table in step from the Stripe webhook; this
 * reflects the same event onto the owner's entitlement subscription so feature
 * access follows the paid plan.
 */
class SyncEntitlementsFromWebhook
{
    public function __construct(private BillingProvider $billing) {}

    public function handle(WebhookReceived $event): void
    {
        $this->billing->syncFromWebhook($event->payload);
    }
}
