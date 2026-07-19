<?php

namespace Whilesmart\EntitlementsCashier;

use Stripe\StripeClient;
use Whilesmart\Entitlements\Models\Plan;

/**
 * Makes the plan's amount the single source of truth for its price: for each
 * priced plan it ensures a matching Stripe Price exists and records the id on
 * the plan, so a host never maintains a second price id by hand.
 *
 * The amount is read from the plan's `metadata.price`
 * (`amount_cents`, `currency`, `interval`). Stripe Prices are immutable, so a
 * new one is created only when the amount, currency or interval changes; the
 * previous stays for existing subscribers.
 */
class PlanPriceSync
{
    public function run(): void
    {
        if (blank(config('cashier.secret'))) {
            return;
        }

        $model = config('entitlements.models.plan', Plan::class);

        foreach ($model::all() as $plan) {
            $this->ensure($plan);
        }
    }

    public function ensure(Plan $plan): void
    {
        $price = (array) data_get($plan->metadata, 'price', []);
        $amount = (int) ($price['amount_cents'] ?? 0);

        if ($amount <= 0 || blank(config('cashier.secret'))) {
            return;
        }

        $currency = strtolower($price['currency'] ?? 'usd');
        $interval = $price['interval'] ?? 'month';
        $signature = "{$amount}:{$currency}:{$interval}";

        if (filled($plan->provider_price_id) && data_get($plan->metadata, 'price_signature') === $signature) {
            return;
        }

        $created = $this->stripe()->prices->create([
            'unit_amount' => $amount,
            'currency' => $currency,
            'recurring' => ['interval' => $interval],
            'product_data' => ['name' => $plan->name],
        ]);

        $plan->update([
            'provider_price_id' => $created->id,
            'metadata' => array_merge($plan->metadata ?? [], ['price_signature' => $signature]),
        ]);
    }

    protected function stripe(): StripeClient
    {
        return new StripeClient((string) config('cashier.secret'));
    }
}
