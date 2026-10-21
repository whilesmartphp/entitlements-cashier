<?php

namespace Tests\Feature;

use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Tests\Fixtures\Owner;
use Tests\TestCase;
use Whilesmart\Entitlements\Contracts\BillingProvider;
use Whilesmart\Entitlements\Models\Plan;
use Whilesmart\Entitlements\Models\Subscription;
use Whilesmart\EntitlementsCashier\CashierBillingProvider;
use Whilesmart\EntitlementsCashier\Models\BillingProfile;

/**
 * Reaches the protected seam, because building a subscription is the part worth
 * asserting and calling checkout would reach Stripe.
 */
class ReadableProvider extends CashierBillingProvider
{
    public function subscriptionUnderTest($owner, Plan $plan)
    {
        return $this->subscriptionFor($owner, $plan);
    }

    public function trialDaysUnderTest(): ?int
    {
        return $this->trialDays();
    }
}

class CashierBillingProviderTest extends TestCase
{
    public function test_billing_email_comes_from_the_current_owner(): void
    {
        $owner = Owner::create(['name' => 'Test Customer']);
        $owner->setAttribute('email', 'customer@example.com');
        $profile = BillingProfile::forOwner($owner);
        $profile->setRelation('owner', $owner);

        $this->assertSame('customer@example.com', $profile->stripeEmail());
        $owner->setAttribute('email', 'updated@example.com');
        $this->assertSame('updated@example.com', $profile->stripeEmail());
    }

    public function test_billing_email_can_be_absent(): void
    {
        $owner = Owner::create(['name' => 'Test Customer']);

        $this->assertNull(BillingProfile::forOwner($owner)->stripeEmail());
    }

    public function test_signed_webhooks_recognize_existing_subscription_prices_after_a_catalog_change(): void
    {
        $subscription = json_decode(file_get_contents(__DIR__.'/../Fixtures/stripe-subscription.json'), true);
        $owner = Owner::create(['name' => 'Test Customer']);
        BillingProfile::forOwner($owner)->update(['stripe_id' => $subscription['customer']]);
        $plan = Plan::create([
            'key' => 'monthly', 'name' => 'Monthly', 'provider_price_id' => 'price_updated',
            'metadata' => ['stripe_price_ids' => [$subscription['items']['data'][0]['price']['id']]],
        ]);
        config(['cashier.secret' => 'your-api-key-here', 'cashier.webhook.secret' => 'your-webhook-secret-here']);
        $client = \Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')->twice()->withArgs(fn ($method, $url) => $method === 'get'
            && str_ends_with($url, '/subscriptions/'.$subscription['id']))
            ->andReturn([json_encode($subscription), 200, []]);
        ApiRequestor::setHttpClient($client);
        $payload = file_get_contents(__DIR__.'/../Fixtures/stripe-checkout-completed.json');

        try {
            $this->call('POST', '/stripe/webhook', server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($payload, 'your-webhook-secret-here'),
            ], content: $payload)->assertOk();
        } finally {
            ApiRequestor::setHttpClient(CurlClient::instance());
        }

        $this->assertSame($plan->id, Subscription::firstOrFail()->plan_id);
    }

    public function test_it_binds_as_the_billing_provider(): void
    {
        $this->assertInstanceOf(CashierBillingProvider::class, app(BillingProvider::class));
    }

    public function test_webhook_fails_closed_without_a_signing_secret(): void
    {
        config(['cashier.webhook.secret' => null]);
        $this->postJson('/stripe/webhook', [])->assertStatus(503);
    }

    public function test_routes_can_be_listed_without_a_webhook_signing_secret(): void
    {
        config(['cashier.webhook.secret' => null]);

        $this->artisan('route:list', ['--path' => 'stripe'])->assertSuccessful();
    }

    public function test_webhook_rejects_an_unsigned_request(): void
    {
        config(['cashier.webhook.secret' => 'your-webhook-secret-here']);
        $this->postJson('/stripe/webhook', [])->assertStatus(403);
    }

    public function test_checkout_requires_a_provider_price(): void
    {
        $owner = Owner::create(['name' => 'Acme']);
        $plan = Plan::create(['key' => 'free', 'name' => 'Free']);

        $this->expectException(RuntimeException::class);

        app(BillingProvider::class)->createCheckout($owner, $plan);
    }

    public function test_billing_profile_is_one_per_owner(): void
    {
        $owner = Owner::create(['name' => 'Acme']);

        $first = BillingProfile::forOwner($owner);
        $second = BillingProfile::forOwner($owner);

        $this->assertSame($first->id, $second->id);
        $this->assertTrue($owner->is($first->owner));
    }

    public function test_no_trial_is_configured_by_default(): void
    {
        $this->assertNull(app(ReadableProvider::class)->trialDaysUnderTest());
    }

    /**
     * @dataProvider notATrial
     */
    public function test_a_setting_that_is_not_a_positive_number_is_no_trial(mixed $setting): void
    {
        config(['entitlements-cashier.trial_days' => $setting]);

        $this->assertNull(app(ReadableProvider::class)->trialDaysUnderTest());
    }

    public static function notATrial(): array
    {
        return [
            'empty string' => [''],
            'zero' => [0],
            'zero as a string' => ['0'],
            'negative' => [-1],
            'negative as a string' => ['-7'],
            'not a number' => ['abc'],
            'a boolean from the environment' => ['true'],
            'a real boolean' => [true],
            'an array' => [[14]],
            'null' => [null],
        ];
    }

    public function test_a_configured_trial_retains_the_requested_days(): void
    {
        config(['entitlements-cashier.trial_days' => 3]);
        $this->assertSame(3, app(ReadableProvider::class)->trialDaysUnderTest());
    }

    public function test_no_trial_leaves_the_subscription_charging_straight_away(): void
    {
        $owner = Owner::create(['name' => 'Acme']);
        $plan = Plan::create(['key' => 'business', 'name' => 'Business', 'provider_price_id' => 'price_biz']);

        $subscription = app(ReadableProvider::class)->subscriptionUnderTest($owner, $plan);

        $this->assertNull((new \ReflectionProperty($subscription, 'trialExpires'))->getValue($subscription));
    }
}
