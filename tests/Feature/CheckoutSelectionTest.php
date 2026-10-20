<?php

namespace Tests\Feature;

use Laravel\Cashier\Checkout;
use Laravel\Cashier\SubscriptionBuilder;
use Stripe\ApiRequestor;
use Stripe\Checkout\Session;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Tests\Fixtures\Owner;
use Tests\TestCase;
use Whilesmart\Entitlements\Models\Plan;
use Whilesmart\EntitlementsCashier\CashierBillingProvider;
use Whilesmart\EntitlementsCashier\Models\BillingProfile;

class CheckoutSelectionTest extends TestCase
{
    public function test_pending_checkout_is_reused_only_for_the_selected_plan_and_price(): void
    {
        $pending = $this->fixture('open');
        $owner = Owner::create(['name' => 'Test Customer']);
        $this->pendingProfile($owner, $pending);
        $plan = $this->plan($pending);
        config(['cashier.secret' => 'your-api-key-here']);
        $client = \Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')->zeroOrMoreTimes()
            ->withArgs(fn ($method, $url) => $method === 'get' && str_ends_with($url, '/checkout/sessions/'.$pending['id']))
            ->andReturn([json_encode($pending), 200, []]);
        ApiRequestor::setHttpClient($client);

        try {
            $this->assertSame($pending['url'], app(CashierBillingProvider::class)->createCheckout($owner, $plan));
        } finally {
            ApiRequestor::setHttpClient(CurlClient::instance());
        }
    }

    public function test_switching_to_yearly_expires_the_monthly_checkout(): void
    {
        $pending = $this->fixture('open');
        $yearly = $this->fixture('yearly');
        $owner = Owner::create(['name' => 'Test Customer']);
        $profile = $this->pendingProfile($owner, $pending);
        $plan = $this->plan($yearly);
        config(['cashier.secret' => 'your-api-key-here']);
        $client = \Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')->once()
            ->withArgs(fn ($method, $url) => $method === 'get' && str_ends_with($url, '/checkout/sessions/'.$pending['id']))
            ->andReturn([json_encode($pending), 200, []]);
        $client->shouldReceive('request')->once()
            ->withArgs(fn ($method, $url) => $method === 'post' && str_ends_with($url, '/checkout/sessions/'.$pending['id'].'/expire'))
            ->andReturn([json_encode($this->fixture('expired')), 200, []]);
        $builder = \Mockery::mock(SubscriptionBuilder::class);
        $builder->shouldReceive('checkout')->once()
            ->withArgs(fn ($options) => $options['metadata']['plan_key'] === $yearly['metadata']['plan_key'])
            ->andReturn(new Checkout($profile, Session::constructFrom($yearly)));
        $provider = \Mockery::mock(CashierBillingProvider::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $provider->shouldReceive('subscriptionFor')->once()->with($owner, $plan)->andReturn($builder);
        ApiRequestor::setHttpClient($client);

        try {
            $this->assertSame($yearly['url'], $provider->createCheckout($owner, $plan));
            $this->assertSame($yearly['id'], $profile->fresh()->checkout_session_id);
        } finally {
            ApiRequestor::setHttpClient(CurlClient::instance());
        }
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/stripe-checkout-'.$name.'.json'), true);
    }

    private function pendingProfile(Owner $owner, array $session): BillingProfile
    {
        $profile = BillingProfile::forOwner($owner);
        $profile->update([
            'stripe_id' => $session['customer'],
            'checkout_session_id' => $session['id'],
            'checkout_url' => $session['url'],
            'checkout_expires_at' => now()->addMinutes(10),
        ]);

        return $profile;
    }

    private function plan(array $session): Plan
    {
        return Plan::create([
            'key' => $session['metadata']['plan_key'], 'name' => 'Test plan',
            'provider_price_id' => $session['line_items']['data'][0]['price']['id'],
        ]);
    }
}
