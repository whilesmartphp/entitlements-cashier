<?php

namespace Tests\Feature;

use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Tests\Fixtures\Owner;
use Tests\TestCase;
use Whilesmart\Entitlements\Enums\SubscriptionStatus;
use Whilesmart\Entitlements\Models\Plan;
use Whilesmart\Entitlements\Models\Subscription;
use Whilesmart\EntitlementsCashier\Models\BillingProfile;

class UnmappedSubscriptionTest extends TestCase
{
    public function test_an_unrelated_customer_is_acknowledged_without_granting_access(): void
    {
        $current = $this->fixture('stripe-subscription');
        $this->deliver('stripe-checkout-completed', $current, 1);
        $this->assertDatabaseCount('entitlement_subscriptions', 0);
    }

    public function test_an_unmapped_price_does_not_create_an_entitlement(): void
    {
        $current = $this->fixture('stripe-subscription');
        $owner = Owner::create(['name' => 'Test Customer']);
        BillingProfile::forOwner($owner)->update(['stripe_id' => $current['customer']]);
        $this->deliver('stripe-checkout-completed', $current);
        $this->assertDatabaseCount('entitlement_subscriptions', 0);
    }

    public function test_cancellation_revokes_access_even_when_the_price_is_not_mapped(): void
    {
        $current = $this->fixture('stripe-subscription-canceled');
        $existing = $this->existing($current);
        $this->assertTrue($existing->isEntitling());

        $this->deliver('stripe-subscription-deleted', $current);

        $this->assertSame(SubscriptionStatus::Canceled, $existing->fresh()->status);
        $this->assertFalse($existing->fresh()->isEntitling());
    }

    public function test_an_unmapped_trial_price_cannot_retain_existing_access(): void
    {
        $current = $this->fixture('stripe-subscription');
        $existing = $this->existing($current);
        $this->deliver('stripe-checkout-completed', $current);
        $this->assertSame(SubscriptionStatus::PastDue, $existing->fresh()->status);
        $this->assertFalse($existing->fresh()->isEntitling());
    }

    private function existing(array $current): Subscription
    {
        $owner = Owner::create(['name' => 'Test Customer']);
        BillingProfile::forOwner($owner)->update(['stripe_id' => $current['customer']]);
        $plan = Plan::create(['key' => 'previous', 'name' => 'Previous plan']);

        return Subscription::create([
            'owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active, 'current_period_end' => now()->addMonth(),
            'metadata' => ['stripe_subscription_id' => $current['id']],
        ]);
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/'.$name.'.json'), true);
    }

    private function deliver(string $fixture, array $current, int $requests = 2): void
    {
        config(['cashier.secret' => 'your-api-key-here', 'cashier.webhook.secret' => 'your-webhook-secret-here']);
        $client = \Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')->times($requests)
            ->withArgs(fn ($method, $url) => $method === 'get' && str_ends_with($url, '/subscriptions/'.$current['id']))
            ->andReturn([json_encode($current), 200, []]);
        ApiRequestor::setHttpClient($client);
        $payload = file_get_contents(__DIR__.'/../Fixtures/'.$fixture.'.json');

        try {
            $this->call('POST', '/stripe/webhook', server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($payload, 'your-webhook-secret-here'),
            ], content: $payload)->assertOk();
        } finally {
            ApiRequestor::setHttpClient(CurlClient::instance());
        }
    }
}
