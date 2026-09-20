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

    // Days of trial a checkout grants before the first charge. Null charges
    // straight away, which is what happens when this is unset.
    'trial_days' => env('ENTITLEMENTS_CASHIER_TRIAL_DAYS'),

    // The plan an owner falls back to when their Stripe subscription is deleted.
    // Null cancels the entitlement subscription instead of granting a plan.
    'default_plan' => env('ENTITLEMENTS_CASHIER_DEFAULT_PLAN'),
];
