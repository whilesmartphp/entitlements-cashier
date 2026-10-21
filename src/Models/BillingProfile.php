<?php

namespace Whilesmart\EntitlementsCashier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Laravel\Cashier\Billable;

/**
 * The Stripe customer for an entitlement owner. Isolated on its own model so
 * Cashier's Billable trait never collides with the owner's domain traits
 * (invoices, subscriptions, ...).
 */
class BillingProfile extends Model
{
    use Billable;

    protected $guarded = ['id'];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'trial_claimed_at' => 'datetime',
        'checkout_expires_at' => 'datetime',
        'checkout_parameters' => 'array',
    ];

    public function getTable(): string
    {
        return config('entitlements-cashier.profiles_table', 'billing_profiles');
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function stripeEmail(): ?string
    {
        return $this->owner?->email;
    }

    /**
     * The billing profile for an owner, created on first use.
     */
    public static function forOwner(Model $owner): self
    {
        return static::firstOrCreate([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
        ]);
    }
}
