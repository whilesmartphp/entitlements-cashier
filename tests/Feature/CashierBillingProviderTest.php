<?php

namespace Tests\Feature;

use RuntimeException;
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
    public function test_it_binds_as_the_billing_provider(): void
    {
        $this->assertInstanceOf(CashierBillingProvider::class, app(BillingProvider::class));
    }

    public function test_checkout_completed_activates_the_owner_plan(): void
    {
        $owner = Owner::create(['name' => 'Acme']);
        $plan = Plan::create(['key' => 'business', 'name' => 'Business', 'provider_price_id' => 'price_biz']);

        app(BillingProvider::class)->syncFromWebhook([
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'customer' => 'cus_1',
                'subscription' => 'sub_1',
                'metadata' => [
                    'owner_type' => $owner->getMorphClass(),
                    'owner_id' => (string) $owner->id,
                    'plan_key' => 'business',
                ],
            ]],
        ]);

        $subscription = Subscription::where('owner_id', $owner->id)->first();

        $this->assertNotNull($subscription);
        $this->assertSame($plan->id, $subscription->plan_id);
        $this->assertSame('active', $subscription->status->value);
        $this->assertSame('sub_1', $subscription->metadata['stripe_subscription_id']);
    }

    public function test_subscription_updated_maps_the_price_to_a_plan(): void
    {
        $owner = Owner::create(['name' => 'Acme']);
        $plan = Plan::create(['key' => 'scale', 'name' => 'Scale', 'provider_price_id' => 'price_scale']);
        BillingProfile::create(['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->id, 'stripe_id' => 'cus_9']);

        app(BillingProvider::class)->syncFromWebhook([
            'type' => 'customer.subscription.updated',
            'data' => ['object' => [
                'id' => 'sub_9',
                'customer' => 'cus_9',
                'status' => 'active',
                'items' => ['data' => [['price' => ['id' => 'price_scale']]]],
            ]],
        ]);

        $subscription = Subscription::where('owner_id', $owner->id)->active()->first();

        $this->assertNotNull($subscription);
        $this->assertSame($plan->id, $subscription->plan_id);
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
        ];
    }

    public function test_a_configured_trial_reaches_the_subscription(): void
    {
        config(['entitlements-cashier.trial_days' => 14]);

        $owner = Owner::create(['name' => 'Acme']);
        $plan = Plan::create(['key' => 'business', 'name' => 'Business', 'provider_price_id' => 'price_biz']);

        $subscription = app(ReadableProvider::class)->subscriptionUnderTest($owner, $plan);

        $expires = (new \ReflectionProperty($subscription, 'trialExpires'))->getValue($subscription);

        $this->assertNotNull($expires);
        $this->assertSame(14, (int) round(now()->diffInDays($expires, false)));
    }

    public function test_no_trial_leaves_the_subscription_charging_straight_away(): void
    {
        $owner = Owner::create(['name' => 'Acme']);
        $plan = Plan::create(['key' => 'business', 'name' => 'Business', 'provider_price_id' => 'price_biz']);

        $subscription = app(ReadableProvider::class)->subscriptionUnderTest($owner, $plan);

        $this->assertNull((new \ReflectionProperty($subscription, 'trialExpires'))->getValue($subscription));
    }
}
