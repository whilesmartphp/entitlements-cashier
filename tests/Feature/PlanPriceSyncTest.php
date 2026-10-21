<?php

namespace Tests\Feature;

use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Tests\TestCase;
use Whilesmart\Entitlements\Models\Plan;
use Whilesmart\EntitlementsCashier\PlanPriceSync;

class PlanPriceSyncTest extends TestCase
{
    public function test_price_changes_retain_previous_price_identifiers(): void
    {
        config()->set('cashier.secret', 'your-api-key-here');
        $plan = Plan::create([
            'key' => 'monthly', 'name' => 'Monthly', 'provider_price_id' => 'price_existing',
            'metadata' => [
                'price' => ['amount_cents' => 899, 'currency' => 'usd', 'interval' => 'month'],
                'price_signature' => '500:usd:month',
                'stripe_price_ids' => ['price_earlier'],
            ],
        ]);
        $response = file_get_contents(__DIR__.'/../Fixtures/stripe-price.json');
        $client = \Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')->once()->withArgs(fn ($method, $url, $headers, $params) => $method === 'post'
            && str_ends_with($url, '/prices') && $params['unit_amount'] === 899)
            ->andReturn([$response, 200, []]);
        ApiRequestor::setHttpClient($client);

        try {
            app(PlanPriceSync::class)->ensure($plan);
            app(PlanPriceSync::class)->ensure($plan);
        } finally {
            ApiRequestor::setHttpClient(CurlClient::instance());
        }

        $plan->refresh();
        $this->assertSame('price_fixture_updated', $plan->provider_price_id);
        $this->assertSame(['price_earlier', 'price_existing', 'price_fixture_updated'], $plan->metadata['stripe_price_ids']);
    }

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
