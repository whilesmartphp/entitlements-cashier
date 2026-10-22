<?php

use Whilesmart\EntitlementsCashier\Models\BillingProfile;

return [
    // The Cashier billable model (the Stripe customer). Swap for a host subclass
    // without touching the package.
    'billing_profile_model' => BillingProfile::class,

    'profiles_table' => env('ENTITLEMENTS_CASHIER_PROFILES_TABLE', 'billing_profiles'),

    // Set false to bring your own migrations (for example when you already
    // publish Cashier's subscription tables yourself).
    'register_migrations' => env('ENTITLEMENTS_CASHIER_REGISTER_MIGRATIONS', true),

    // Where Stripe Checkout returns the customer. Defaults to the app URL.
    'success_url' => env('ENTITLEMENTS_CASHIER_SUCCESS_URL'),
    'cancel_url' => env('ENTITLEMENTS_CASHIER_CANCEL_URL'),
    'portal_return_url' => env('ENTITLEMENTS_CASHIER_PORTAL_RETURN_URL'),

    // Days of trial a checkout grants before the first charge. Null charges
    // straight away, which is what happens when this is unset.
    'trial_days' => env('ENTITLEMENTS_CASHIER_TRIAL_DAYS'),

    // Show a promotion code field on checkout, so a code made in Stripe can be
    // redeemed by the buyer.
    'promotion_codes' => env('ENTITLEMENTS_CASHIER_PROMOTION_CODES', false),

    // Ask for a card only when the checkout has something to charge. A code
    // that takes the whole price off then subscribes without one.
    'card_only_when_due' => env('ENTITLEMENTS_CASHIER_CARD_ONLY_WHEN_DUE', false),

    // The plan an owner falls back to when their Stripe subscription is deleted.
    // Null cancels the entitlement subscription instead of granting a plan.
    'default_plan' => env('ENTITLEMENTS_CASHIER_DEFAULT_PLAN'),
];
