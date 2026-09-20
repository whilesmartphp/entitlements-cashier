## [0.2.0] - 2026-09-20
- A checkout can offer a trial before the first charge, set with `trial_days` (`ENTITLEMENTS_CASHIER_TRIAL_DAYS`). Left unset, a checkout charges straight away as before, and only a positive number is treated as a trial

## [0.1.1] - 2026-07-19
- Plan prices sync to Stripe: a plan's amount (its `metadata.price`) is the single source of truth, and the matching Stripe Price is created and its id recorded on the plan, recreated only when the amount changes
- `entitlements-cashier:sync-prices` command and a `PlanPriceSync` service, so a host never maintains a separate Stripe price id by hand

## [0.1.0] - 2026-07-19
- Cashier billing adapter fulfilling the eloquent-entitlements BillingProvider seam
- BillingProfile: the Cashier customer keyed to a polymorphic entitlement owner, so no per-app billable model is needed
- Checkout through Stripe, and a webhook listener that reflects Stripe subscription state onto the owner's entitlement plan
- Configurable return URLs, a fallback plan on cancellation, and a swappable billable model
