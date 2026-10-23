<?php

namespace Whilesmart\EntitlementsCashier\Discounts;

use Illuminate\Support\Carbon;

/**
 * A Stripe promotion code and the coupon behind it.
 */
final class PromotionCode
{
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly bool $active,
        public readonly ?float $percentOff = null,
        public readonly ?int $amountOff = null,
        public readonly ?string $currency = null,
        public readonly ?string $duration = null,
        public readonly ?int $months = null,
        public readonly ?Carbon $expiresAt = null,
        public readonly int $redemptions = 0,
        public readonly ?int $maxRedemptions = null,
        public readonly ?string $customer = null,
        public readonly bool $couponDeleted = false,
    ) {}

    public static function fromStripe(object $code, ?object $coupon = null): self
    {
        $coupon ??= $code->promotion->coupon;
        $deleted = ! empty($coupon->deleted);

        return new self(
            id: $code->id,
            code: $code->code,
            // A coupon deleted in Stripe stays on its codes as a bare id, and the
            // codes stop working with it.
            active: (bool) $code->active && ! $deleted,
            percentOff: $deleted || $coupon->percent_off === null ? null : (float) $coupon->percent_off,
            amountOff: $deleted ? null : $coupon->amount_off,
            currency: $deleted ? null : $coupon->currency,
            duration: $deleted ? null : $coupon->duration,
            months: $deleted ? null : $coupon->duration_in_months,
            expiresAt: $code->expires_at ? Carbon::createFromTimestamp($code->expires_at) : null,
            redemptions: (int) $code->times_redeemed,
            maxRedemptions: $code->max_redemptions,
            customer: is_string($code->customer) ? $code->customer : null,
            couponDeleted: $deleted,
        );
    }
}
