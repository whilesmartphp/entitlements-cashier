# Entitlements Cashier

A Laravel Cashier billing adapter for [`whilesmart/eloquent-entitlements`](https://github.com/whilesmartphp/eloquent-entitlements). It fulfils the entitlements `BillingProvider` seam so any entitlement owner (a workspace, team, or user) can check out through Stripe and have its subscription state reflected onto its plan, without each app rebuilding the wiring.

## Why

`eloquent-entitlements` owns the seam (`BillingProvider`) and stays payment-SDK-neutral. This package is the Cashier implementation of that seam, shipped once so a host does not hand-roll a billable model, a provider, a webhook listener, and migrations in every project.

## Install

```
composer require whilesmart/entitlements-cashier
php artisan migrate
```

Cashier reads `STRIPE_KEY`, `STRIPE_SECRET`, and `STRIPE_WEBHOOK_SECRET` from the environment. Point your Stripe webhook at Cashier's route (`/stripe/webhook`). Set each paid plan's Stripe Price ID on its `provider_price_id`.

That is the whole integration. The service provider binds the `BillingProvider`, makes the billing profile Cashier's customer model, registers the webhook listener, and loads its migrations.

## How it works

- **`BillingProfile`** is the Cashier customer, keyed to a polymorphic `owner`. It is separate from the owner model so Cashier's `Billable` trait never collides with the owner's own `invoices()` / `subscriptions()` relations.
- **`createCheckout($owner, $plan)`** opens a Stripe Checkout session for the plan's `provider_price_id` and returns the redirect URL.
- **The webhook listener** reflects `checkout.session.completed`, `customer.subscription.updated`, and `customer.subscription.deleted` onto the owner's entitlement subscription: the paid plan is granted, changed, or (on deletion) replaced with the configured `default_plan` or cancelled.

## Prices

A plan's amount is the single source of truth; you do not maintain a Stripe price id. Put the amount on the plan's `metadata.price` (`amount_cents`, `currency`, `interval`), then run:

```
php artisan entitlements-cashier:sync-prices
```

For each priced plan this creates a matching Stripe Price and records its id on the plan's `provider_price_id`. Stripe Prices are immutable, so a new one is created only when the amount, currency, or interval changes; the previous stays for existing subscribers. `PlanPriceSync` is also resolvable if you prefer to run it from your own seeder.

## Configuration

Publish with `php artisan vendor:publish --tag=entitlements-cashier-config`.

| Key | Purpose |
|-----|---------|
| `billing_profile_model` | Swap the Cashier billable model for a host subclass. |
| `profiles_table` | The billing profiles table name. |
| `success_url` / `cancel_url` | Where Stripe Checkout returns; defaults to the app URL. |
| `default_plan` | Plan an owner falls back to when its Stripe subscription is deleted. Null cancels the entitlement instead. |
| `register_migrations` | Set false to bring your own migrations. |

## Testing

```
composer test
```
