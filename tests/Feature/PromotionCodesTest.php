<?php

namespace Tests\Feature;

use InvalidArgumentException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Tests\Fixtures\Owner;
use Tests\TestCase;
use Whilesmart\EntitlementsCashier\Discounts\PromotionCodes;
use Whilesmart\EntitlementsCashier\Models\BillingProfile;

class PromotionCodesTransport implements ClientInterface
{
    /** @var array<int, array{method: string, path: string, params: array}> */
    public array $requests = [];

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $path = (string) parse_url($absUrl, PHP_URL_PATH);
        $this->requests[] = ['method' => $method, 'path' => $path, 'params' => $params];
        $codes = json_decode(file_get_contents(__DIR__.'/../Fixtures/stripe-promotion-codes.json'), true);

        return match (true) {
            $method === 'get' && $path === '/v1/promotion_codes' => [json_encode($codes), 200, []],
            $method === 'post' && $path === '/v1/coupons' => [file_get_contents(__DIR__.'/../Fixtures/stripe-coupon.json'), 200, []],
            $method === 'post' && str_starts_with($path, '/v1/promotion_codes') => [json_encode($codes['data'][0]), 200, []],
            $method === 'get' && $path === '/v1/subscriptions' => [file_get_contents(__DIR__.'/../Fixtures/stripe-subscriptions-discounted.json'), 200, []],
            $method === 'post' && str_starts_with($path, '/v1/subscriptions/') => [file_get_contents(__DIR__.'/../Fixtures/stripe-subscription-discount-ended.json'), 200, []],
        };
    }
}

class PromotionCodesTest extends TestCase
{
    private PromotionCodesTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cashier.secret' => 'your-api-key-here']);
        $this->transport = new PromotionCodesTransport;
        ApiRequestor::setHttpClient($this->transport);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());
        parent::tearDown();
    }

    public function test_a_code_whose_coupon_was_deleted_in_stripe_no_longer_works(): void
    {
        $codes = app(PromotionCodes::class)->all();

        $this->assertCount(5, $codes);
        $this->assertSame('JUSTFORFON', $codes[0]->code);
        $this->assertTrue($codes[0]->couponDeleted);
        $this->assertFalse($codes[0]->active);
        $this->assertNull($codes[0]->percentOff);
    }

    public function test_a_code_held_for_one_owner_is_made_against_their_stripe_customer(): void
    {
        $owner = Owner::create(['name' => 'Ada']);
        BillingProfile::forOwner($owner)->update(['stripe_id' => 'cus_ada']);

        $code = app(PromotionCodes::class)->create('PILOT3FREE', PromotionCodes::REPEATING, percentOff: 100, months: 3, for: $owner);

        [$coupon, $promotion] = $this->transport->requests;
        $this->assertSame(['duration' => 'repeating', 'name' => 'PILOT3FREE', 'percent_off' => 100.0, 'duration_in_months' => 3], $coupon['params']);
        $this->assertSame('SFIaOG95', $promotion['params']['promotion']['coupon']);
        $this->assertSame('cus_ada', $promotion['params']['customer']);
        $this->assertSame(50.0, $code->percentOff);
        $this->assertSame('once', $code->duration);
    }

    public function test_a_code_cannot_be_held_for_an_owner_who_has_not_reached_billing(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('They have no billing account yet, so a code cannot be held for them.'));

        try {
            app(PromotionCodes::class)->create('PILOT20', PromotionCodes::ONCE, percentOff: 20, for: Owner::create(['name' => 'Ada']));
        } finally {
            $this->assertSame([], $this->transport->requests);
        }
    }

    public function test_a_code_takes_a_percentage_or_an_amount_off(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(PromotionCodes::class)->create('PILOT20', PromotionCodes::ONCE, percentOff: 20, amountOff: 5);
    }

    public function test_ending_a_code_takes_it_off_the_subscriptions_carrying_it(): void
    {
        $ended = app(PromotionCodes::class)->end('promo_1UTYHHEJCuaVh7cirXXRMtxC');

        $this->assertSame(1, $ended);
        $this->assertSame(['active' => 'false'], $this->transport->requests[0]['params']);
        $updates = array_values(array_filter($this->transport->requests, fn ($request) => $request['method'] === 'post' && str_starts_with($request['path'], '/v1/subscriptions/')));
        $this->assertCount(1, $updates);
        $this->assertSame('/v1/subscriptions/sub_1USmeZEJCuaVh7ci1CifKEX6', $updates[0]['path']);
        $this->assertSame(['discounts' => ''], $updates[0]['params']);
    }

    public function test_ending_a_code_nobody_carries_changes_no_subscription(): void
    {
        $this->assertSame(0, app(PromotionCodes::class)->end('promo_unused'));
        $this->assertSame([], array_filter($this->transport->requests, fn ($request) => str_starts_with($request['path'], '/v1/subscriptions/')));
    }

    public function test_stopping_a_code_turns_it_off_in_stripe(): void
    {
        app(PromotionCodes::class)->stop('promo_1');

        $this->assertSame('/v1/promotion_codes/promo_1', $this->transport->requests[0]['path']);
        $this->assertSame(['active' => 'false'], $this->transport->requests[0]['params']);
    }
}
