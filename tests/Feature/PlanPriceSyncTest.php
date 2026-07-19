<?php

namespace Tests\Feature;

use Tests\TestCase;
use Whilesmart\Entitlements\Models\Plan;
use Whilesmart\EntitlementsCashier\PlanPriceSync;

class PlanPriceSyncTest extends TestCase
{
    public function test_run_is_a_no_op_without_billing_configured(): void
    {
        config()->set('cashier.secret', null);
        $plan = Plan::create([
            'key' => 'business',
            'name' => 'Business',
            'metadata' => ['price' => ['amount_cents' => 100, 'currency' => 'usd', 'interval' => 'month']],
        ]);

        app(PlanPriceSync::class)->run();

        $this->assertNull($plan->fresh()->provider_price_id);
    }

    public function test_ensure_skips_free_plans(): void
    {
        config()->set('cashier.secret', 'sk_test_x');
        $plan = Plan::create([
            'key' => 'solo',
            'name' => 'Solo',
            'metadata' => ['price' => ['amount_cents' => 0, 'currency' => 'usd', 'interval' => 'month']],
        ]);

        app(PlanPriceSync::class)->ensure($plan);

        $this->assertNull($plan->fresh()->provider_price_id);
    }

    public function test_ensure_skips_when_the_price_is_unchanged(): void
    {
        config()->set('cashier.secret', 'sk_test_x');
        $plan = Plan::create([
            'key' => 'business',
            'name' => 'Business',
            'provider_price_id' => 'price_existing',
            'metadata' => [
                'price' => ['amount_cents' => 100, 'currency' => 'usd', 'interval' => 'month'],
                'price_signature' => '100:usd:month',
            ],
        ]);

        // The signature matches, so it returns before ever calling Stripe.
        app(PlanPriceSync::class)->ensure($plan);

        $this->assertSame('price_existing', $plan->fresh()->provider_price_id);
    }
}
