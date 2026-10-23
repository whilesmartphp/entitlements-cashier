<?php

namespace Whilesmart\EntitlementsCashier\Discounts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Laravel\Cashier\Cashier;
use Stripe\StripeClient;
use Whilesmart\EntitlementsCashier\Models\BillingProfile;

/**
 * Discount codes a buyer types at checkout, kept in Stripe.
 *
 * A code is a Stripe promotion code on a coupon of its own: the coupon says what
 * comes off and for how long, the code is what a person types. Stripe applies it,
 * so no copy is held here.
 */
class PromotionCodes
{
    public const ONCE = 'once';

    public const REPEATING = 'repeating';

    public const FOREVER = 'forever';

    public function __construct(private readonly ?StripeClient $stripe = null) {}

    /** @return PromotionCode[] */
    public function all(): array
    {
        $codes = $this->client()->promotionCodes->all([
            'limit' => 100,
            'expand' => ['data.promotion.coupon'],
        ]);

        return array_map(
            fn ($code) => PromotionCode::fromStripe($code),
            iterator_to_array($codes->autoPagingIterator(), false),
        );
    }

    /**
     * @param  float|null  $amountOff  In the currency's main unit, off each bill.
     * @param  Model|null  $for  The one owner who may use the code. They need a
     *                           Stripe customer, which their first checkout makes.
     *
     * @throws InvalidArgumentException When the code cannot be made as asked.
     */
    public function create(
        string $code,
        string $duration,
        ?float $percentOff = null,
        ?float $amountOff = null,
        ?int $months = null,
        ?int $maxRedemptions = null,
        ?Carbon $expiresAt = null,
        ?Model $for = null,
    ): PromotionCode {
        if (trim($code) === '') {
            throw new InvalidArgumentException('Give the code people will type at checkout.');
        }
        if (! in_array($duration, [self::ONCE, self::REPEATING, self::FOREVER], true)) {
            throw new InvalidArgumentException('That is not a length a discount can last.');
        }
        if (($percentOff === null) === ($amountOff === null)) {
            throw new InvalidArgumentException('Take off a percentage or an amount, not both and not neither.');
        }
        if ($duration === self::REPEATING && $months === null) {
            throw new InvalidArgumentException('Say how many months it lasts.');
        }

        $customer = $for === null ? null : $this->customerFor($for);

        $coupon = ['duration' => $duration, 'name' => $code];
        if ($percentOff !== null) {
            $coupon['percent_off'] = $percentOff;
        } else {
            $coupon['amount_off'] = (int) round($amountOff * 100);
            $coupon['currency'] = strtolower((string) config('cashier.currency', 'usd'));
        }
        if ($duration === self::REPEATING) {
            $coupon['duration_in_months'] = $months;
        }

        $created = $this->client()->coupons->create($coupon);

        // The coupon sits under `promotion` rather than beside the code: the API
        // version Cashier pins nests what is offered inside the code offering it.
        $promotion = ['promotion' => ['type' => 'coupon', 'coupon' => $created->id], 'code' => $code];
        if ($maxRedemptions !== null) {
            $promotion['max_redemptions'] = $maxRedemptions;
        }
        if ($expiresAt !== null) {
            $promotion['expires_at'] = $expiresAt->timestamp;
        }
        if ($customer !== null) {
            $promotion['customer'] = $customer;
        }

        return PromotionCode::fromStripe($this->client()->promotionCodes->create($promotion), $created);
    }

    /** Nobody new can use the code. Subscriptions that already carry it keep it. */
    public function stop(string $id): void
    {
        $this->client()->promotionCodes->update($id, ['active' => false]);
    }

    /**
     * Stop the code and take it off every subscription carrying it, from their
     * next bill. A code that lasts forever otherwise never ends for them.
     *
     * @return int How many subscriptions it was taken off.
     */
    public function end(string $id): int
    {
        $this->stop($id);

        $subscriptions = $this->client()->subscriptions->all(['limit' => 100, 'expand' => ['data.discounts']]);
        $ended = 0;

        foreach ($subscriptions->autoPagingIterator() as $subscription) {
            $kept = array_values(array_filter(
                $subscription->discounts,
                fn ($discount) => ($discount->promotion_code ?? null) !== $id,
            ));

            if (count($kept) === count($subscription->discounts)) {
                continue;
            }

            // Every discount to keep is named again: whatever the update leaves
            // out is removed, and an empty string removes them all.
            $this->client()->subscriptions->update($subscription->id, [
                'discounts' => $kept === [] ? '' : array_map(fn ($discount) => ['discount' => $discount->id], $kept),
            ]);
            $ended++;
        }

        return $ended;
    }

    private function customerFor(Model $owner): string
    {
        $profile = config('entitlements-cashier.billing_profile_model', BillingProfile::class);
        $stripeId = $profile::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->whereNotNull('stripe_id')
            ->value('stripe_id');

        if ($stripeId === null) {
            throw new InvalidArgumentException('They have no billing account yet, so a code cannot be held for them.');
        }

        return (string) $stripeId;
    }

    private function client(): StripeClient
    {
        return $this->stripe ?? Cashier::stripe();
    }
}
