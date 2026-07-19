## [0.1.0] - 2026-07-19
- Cashier billing adapter fulfilling the eloquent-entitlements BillingProvider seam
- BillingProfile: the Cashier customer keyed to a polymorphic entitlement owner, so no per-app billable model is needed
- Checkout through Stripe, and a webhook listener that reflects Stripe subscription state onto the owner's entitlement plan
- Configurable return URLs, a fallback plan on cancellation, and a swappable billable model
