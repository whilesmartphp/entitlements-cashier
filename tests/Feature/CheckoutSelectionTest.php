<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use PHPUnit\Framework\Assert;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Tests\Fixtures\Owner;
use Tests\TestCase;
use Whilesmart\Entitlements\Contracts\BillingProvider;
use Whilesmart\Entitlements\Models\Plan;
use Whilesmart\EntitlementsCashier\CashierBillingProvider;
use Whilesmart\EntitlementsCashier\Models\BillingProfile;

class CheckoutTransport implements ClientInterface
{
    public bool $interrupt = false;

    public bool $complete = false;

    public bool $interruptExpiry = false;

    public bool $expired = false;

    public array $creates = [];

    public array $keys = [];

    public int $expires = 0;

    public int $retrieves = 0;

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        if ($method === 'get') {
            $this->retrieves++;

            if ($this->complete) {
                $event = json_decode(file_get_contents(__DIR__.'/../Fixtures/stripe-checkout-completed.json'), true);

                return [json_encode($event['data']['object']), 200, []];
            }

            return [file_get_contents(__DIR__.'/../Fixtures/stripe-checkout-'.($this->expired ? 'expired' : 'open').'.json'), 200, []];
        }
        if (str_ends_with($absUrl, '/expire')) {
            $this->expires++;
            $this->expired = true;
            if ($this->interruptExpiry) {
                $this->interruptExpiry = false;
                throw new \RuntimeException('Cancellation response was interrupted.');
            }

            return [file_get_contents(__DIR__.'/../Fixtures/stripe-checkout-expired.json'), 200, []];
        }
        Assert::assertStringEndsWith('/checkout/sessions', $absUrl);
        $this->creates[] = $params;
        $this->keys[] = array_values(array_filter($headers, fn ($header) => str_starts_with($header, 'Idempotency-Key: ')));
        Assert::assertCount(1, end($this->keys));
        if ($this->interrupt) {
            $this->interrupt = false;
            throw new \RuntimeException('Checkout response was interrupted.');
        }
        $yearly = json_decode(file_get_contents(__DIR__.'/../Fixtures/stripe-checkout-yearly.json'), true);
        $name = $params['line_items'][0]['price'] === $yearly['line_items']['data'][0]['price']['id'] ? 'yearly' : 'open';

        return [file_get_contents(__DIR__.'/../Fixtures/stripe-checkout-'.$name.'.json'), 200, []];
    }
}

class CheckoutSelectionTest extends TestCase
{
    private CheckoutTransport $transport;

    private Owner $owner;

    private BillingProfile $profile;

    private Plan $monthly;

    protected function defineRoutes($router): void
    {
        $router->post('/checkout/{owner}', fn (int $owner, Request $request) => ['url' => app(BillingProvider::class)->createCheckout(Owner::findOrFail($owner), Plan::where('key', $request->input('plan'))->firstOrFail())]);
        $router->post('/checkout/{owner}/cancel', function (int $owner) {
            app(CashierBillingProvider::class)->expireCheckout(Owner::findOrFail($owner));

            return response()->noContent();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config(['cashier.secret' => 'your-api-key-here', 'cache.default' => 'array', 'entitlements-cashier.trial_days' => 7]);
        $this->owner = Owner::create(['name' => 'Test Customer']);
        $this->profile = BillingProfile::forOwner($this->owner);
        $this->profile->update(['stripe_id' => $this->fixture('open')['customer']]);
        $this->monthly = $this->plan($this->fixture('open'));
        $this->transport = new CheckoutTransport;
        ApiRequestor::setHttpClient($this->transport);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());
        parent::tearDown();
    }

    public function test_pending_checkout_is_reused_only_for_the_selected_plan_and_price(): void
    {
        $pending = $this->fixture('open');
        $this->pendingProfile($pending);
        $this->postJson('/checkout/'.$this->owner->id, ['plan' => $this->monthly->key])->assertOk()->assertJsonPath('url', $pending['url']);
        $this->assertSame(1, $this->transport->retrieves);
        $this->assertSame([], $this->transport->creates);
    }

    public function test_checkout_offers_no_promotion_code_and_always_asks_for_a_card_by_default(): void
    {
        $this->postJson('/checkout/'.$this->owner->id, ['plan' => $this->monthly->key])->assertOk();

        $this->assertArrayNotHasKey('allow_promotion_codes', $this->transport->creates[0]);
        $this->assertArrayNotHasKey('payment_method_collection', $this->transport->creates[0]);
    }

    public function test_checkout_takes_a_promotion_code_and_skips_the_card_when_nothing_is_due(): void
    {
        config(['entitlements-cashier.promotion_codes' => 'true', 'entitlements-cashier.card_only_when_due' => 'true']);

        $this->postJson('/checkout/'.$this->owner->id, ['plan' => $this->monthly->key])->assertOk();

        $this->assertSame('true', $this->transport->creates[0]['allow_promotion_codes']);
        $this->assertSame('if_required', $this->transport->creates[0]['payment_method_collection']);
    }

    public function test_switching_to_yearly_expires_the_monthly_checkout(): void
    {
        $this->pendingProfile($this->fixture('open'));
        $yearly = $this->plan($this->fixture('yearly'));
        $this->postJson('/checkout/'.$this->owner->id, ['plan' => $yearly->key])->assertOk()->assertJsonPath('url', $this->fixture('yearly')['url']);
        $this->assertSame(1, $this->transport->expires);
        $this->assertSame($this->fixture('yearly')['id'], $this->profile->fresh()->checkout_session_id);
    }

    public function test_interrupted_checkout_reuses_the_saved_request_after_settings_change(): void
    {
        $this->transport->interrupt = true;
        $this->postJson('/checkout/'.$this->owner->id, ['plan' => $this->monthly->key])->assertStatus(500);
        $profile = $this->profile->fresh();
        $this->assertNotNull($profile->checkout_request_key);
        $this->assertNull($profile->checkout_session_id);
        $this->assertSame(7, $profile->checkout_parameters['subscription_data']['trial_period_days']);
        $this->assertSame('default', $profile->checkout_parameters['subscription_data']['metadata']['type']);
        $this->travel(2)->minutes();
        config(['entitlements-cashier.trial_days' => 14, 'entitlements-cashier.success_url' => 'https://example.com/changed']);
        $this->postJson('/checkout/'.$this->owner->id, ['plan' => $this->monthly->key])->assertOk();
        $this->assertSame($this->transport->creates[0], $this->transport->creates[1]);
        $this->assertSame($this->transport->keys[0], $this->transport->keys[1]);
        $this->postJson('/checkout/'.$this->owner->id, ['plan' => $this->monthly->key])->assertOk();
        $this->assertCount(2, $this->transport->creates);
    }

    public function test_interrupted_checkout_can_be_canceled_without_changing_its_request(): void
    {
        $this->transport->interrupt = true;
        $this->postJson('/checkout/'.$this->owner->id, ['plan' => $this->monthly->key])->assertStatus(500);
        config(['entitlements-cashier.trial_days' => 14]);
        $this->postJson('/checkout/'.$this->owner->id.'/cancel')->assertNoContent();
        $this->assertSame($this->transport->creates[0], $this->transport->creates[1]);
        $this->assertSame($this->transport->keys[0], $this->transport->keys[1]);
        $this->assertSame(1, $this->transport->expires);
        $this->assertNull($this->profile->fresh()->checkout_request_key);
        $this->postJson('/checkout/'.$this->owner->id.'/cancel')->assertNoContent();
        $this->assertSame(1, $this->transport->expires);
        $this->postJson('/checkout/'.$this->owner->id, ['plan' => $this->monthly->key])->assertOk();
        $this->assertSame(14, $this->transport->creates[2]['subscription_data']['trial_period_days']);
        $this->assertNotSame($this->transport->keys[0], $this->transport->keys[2]);
    }

    public function test_plan_change_recovers_and_expires_an_interrupted_checkout_first(): void
    {
        $this->transport->interrupt = true;
        $this->postJson('/checkout/'.$this->owner->id, ['plan' => $this->monthly->key])->assertStatus(500);
        $yearly = $this->plan($this->fixture('yearly'));
        $this->postJson('/checkout/'.$this->owner->id, ['plan' => $yearly->key])->assertOk();
        $this->assertSame($this->transport->creates[0], $this->transport->creates[1]);
        $this->assertSame($this->transport->keys[0], $this->transport->keys[1]);
        $this->assertNotSame($this->transport->keys[1], $this->transport->keys[2]);
        $this->assertSame(1, $this->transport->expires);
        $this->assertSame($yearly->provider_price_id, $this->transport->creates[2]['line_items'][0]['price']);
    }

    public function test_recovered_checkout_checks_current_status_before_changing_plans(): void
    {
        $this->transport->interrupt = true;
        $this->postJson('/checkout/'.$this->owner->id, ['plan' => $this->monthly->key])->assertStatus(500);
        $this->transport->complete = true;
        $yearly = $this->plan($this->fixture('yearly'));
        $this->postJson('/checkout/'.$this->owner->id, ['plan' => $yearly->key])->assertStatus(500);
        $this->postJson('/checkout/'.$this->owner->id.'/cancel')->assertStatus(500);
        $this->assertCount(2, $this->transport->creates);
        $this->assertSame($this->transport->keys[0], $this->transport->keys[1]);
        $this->assertSame(0, $this->transport->expires);
    }

    public function test_interrupted_cancellation_can_be_confirmed_without_recreating_checkout(): void
    {
        $this->transport->interrupt = true;
        $this->postJson('/checkout/'.$this->owner->id, ['plan' => $this->monthly->key])->assertStatus(500);
        $this->transport->interruptExpiry = true;
        $this->postJson('/checkout/'.$this->owner->id.'/cancel')->assertStatus(500);
        $this->assertSame($this->fixture('open')['id'], $this->profile->fresh()->checkout_session_id);
        $this->postJson('/checkout/'.$this->owner->id.'/cancel')->assertNoContent();
        $this->assertCount(2, $this->transport->creates);
        $this->assertSame(1, $this->transport->expires);
        $this->assertNull($this->profile->fresh()->checkout_request_key);
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/stripe-checkout-'.$name.'.json'), true);
    }

    private function pendingProfile(array $session): void
    {
        $this->profile->update(['checkout_session_id' => $session['id'], 'checkout_url' => $session['url'], 'checkout_expires_at' => now()->addMinutes(10)]);
    }

    private function plan(array $session): Plan
    {
        return Plan::create(['key' => $session['metadata']['plan_key'], 'name' => 'Test plan', 'provider_price_id' => $session['line_items']['data'][0]['price']['id']]);
    }
}
