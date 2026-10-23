<?php

namespace Whilesmart\EntitlementsCashier;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\SubscriptionBuilder;
use RuntimeException;
use Stripe\Checkout\Session;
use Whilesmart\Entitlements\Contracts\BillingProvider;
use Whilesmart\Entitlements\Enums\SubscriptionStatus;
use Whilesmart\Entitlements\Models\Plan;
use Whilesmart\Entitlements\Models\Subscription;
use Whilesmart\EntitlementsCashier\Models\BillingProfile;

/**
 * Fulfils the entitlements checkout seam with Cashier. Cashier owns the Stripe
 * side (customer, subscription, portal); this reflects the paid state back onto
 * the owner's entitlement subscription. Everything here is owner-agnostic: it
 * works for whatever model owns entitlements, resolved from the seam and the
 * plan's provider price.
 */
class CashierBillingProvider implements BillingProvider
{
    public function createCheckout(Model $owner, Plan $plan): string
    {
        if (blank($plan->provider_price_id)) {
            throw new RuntimeException("Plan {$plan->key} has no provider price configured.");
        }

        $profile = $this->profileFor($owner);

        return Cache::lock($this->checkoutLock($profile), 300)->block(5, function () use ($owner, $plan, $profile) {
            $profile->refresh();
            $this->assertCanCheckout($owner, $profile);
            if ($profile->hasStripeId() && filled($profile->stripeEmail())) {
                $profile->updateStripeCustomer(['email' => $profile->stripeEmail()]);
            }
            $pending = $this->pendingCheckout($profile);
            if ($pending?->status === 'complete') {
                throw new RuntimeException('Checkout is complete. Refresh billing status before changing plans.');
            }
            if ($pending && ! in_array($pending->status, ['open', 'expired'], true)) {
                throw new RuntimeException('Checkout status could not be confirmed.');
            }
            if ($pending?->status === 'open') {
                $price = $profile->checkout_parameters['line_items'][0]['price'] ?? $pending->line_items->data[0]->price->id ?? null;
                if (($pending->metadata->plan_key ?? null) === $plan->key && $price === $plan->provider_price_id) {
                    $this->rememberCheckout($profile, $pending);

                    return $pending->url;
                }
                Cashier::stripe()->checkout->sessions->expire($pending->id, []);
            }
            $profile->getConnection()->transaction(function () use ($profile, $owner, $plan) {
                $profile = $profile->newQuery()->whereKey($profile->getKey())->lockForUpdate()->firstOrFail();
                $this->assertCanCheckout($owner, $profile);
                $parameters = $this->checkoutParameters($owner, $plan, $profile);
                $profile->update([
                    'checkout_request_key' => (string) Str::uuid(), 'checkout_parameters' => $parameters,
                    'checkout_expires_at' => Carbon::createFromTimestampUTC($parameters['expires_at']),
                    'checkout_session_id' => null, 'checkout_url' => null,
                ]);
            });
            $profile->refresh();
            $checkout = $this->sendCheckout($profile);
            $this->rememberCheckout($profile, $checkout);

            return $checkout->url;
        });
    }

    public function expireCheckout(Model $owner): void
    {
        $profile = $this->profileFor($owner);
        Cache::lock($this->checkoutLock($profile), 300)->block(5, function () use ($profile) {
            $profile->refresh();
            $pending = $this->pendingCheckout($profile);
            if ($pending?->status === 'complete') {
                throw new RuntimeException('Checkout is complete. Refresh billing status before canceling it.');
            }
            if ($pending?->status === 'open') {
                $pending = Cashier::stripe()->checkout->sessions->expire($pending->id, []);
            }
            if ($pending && $pending->status !== 'expired') {
                throw new RuntimeException('Checkout cancellation could not be confirmed.');
            }
            $profile->update(['checkout_request_key' => null, 'checkout_parameters' => null,
                'checkout_session_id' => null, 'checkout_url' => null, 'checkout_expires_at' => null]);
        });
    }

    protected function checkoutParameters(Model $owner, Plan $plan, BillingProfile $profile): array
    {
        $reference = ['owner_type' => $owner->getMorphClass(), 'owner_id' => (string) $owner->getKey(), 'plan_key' => $plan->key];
        if (! $profile->hasStripeId()) {
            $profile->createAsStripeCustomer();
        }
        $subscription = ['metadata' => [...$reference, 'name' => 'default', 'type' => 'default', 'is_on_session_checkout' => true]];
        if ($profile->trial_claimed_at === null && ($days = $this->trialDays()) !== null) {
            $subscription['trial_period_days'] = $days;
        }
        if ($rates = $profile->taxRates()) {
            $subscription['default_tax_rates'] = $rates;
        }
        $parameters = [
            'customer' => $profile->stripe_id, 'mode' => 'subscription',
            'line_items' => array_values($this->subscriptionFor($owner, $plan)->getItems()),
            'success_url' => $this->returnUrl('success'), 'cancel_url' => $this->returnUrl('cancel'),
            'metadata' => $reference, 'subscription_data' => $subscription,
            'expires_at' => now()->addMinutes(30)->timestamp,
        ];
        if (config('entitlements-cashier.promotion_codes', true)) {
            $parameters['allow_promotion_codes'] = true;
        }
        if (Cashier::$calculatesTaxes) {
            $parameters['automatic_tax'] = ['enabled' => true];
            $parameters['tax_id_collection'] = ['enabled' => true];
            $parameters['billing_address_collection'] = 'required';
            $parameters['customer_update'] = ['name' => 'auto'];
        }

        return $parameters;
    }

    private function assertCanCheckout(Model $owner, BillingProfile $profile): void
    {
        $subscription = $this->subscriptionForOwner($owner);
        $cashierSubscription = $profile->subscription('default');
        if (($subscription !== null && $subscription->status !== SubscriptionStatus::Canceled
            && ! empty($subscription->metadata['stripe_subscription_id']))
            || ($cashierSubscription !== null && ! in_array($cashierSubscription->stripe_status, ['canceled', 'incomplete_expired'], true))) {
            throw new RuntimeException('A subscription already exists. Use billing management to change it.');
        }
    }

    private function pendingCheckout(BillingProfile $profile): ?Session
    {
        if (! $profile->checkout_expires_at?->isFuture()) {
            return null;
        }
        if ($profile->checkout_session_id) {
            return Cashier::stripe()->checkout->sessions->retrieve($profile->checkout_session_id, ['expand' => ['line_items']]);
        }

        if (! $profile->checkout_request_key || ! $profile->checkout_parameters) {
            return null;
        }
        $session = $this->sendCheckout($profile);
        $this->rememberCheckout($profile, $session);

        return Cashier::stripe()->checkout->sessions->retrieve($session->id, ['expand' => ['line_items']]);
    }

    private function sendCheckout(BillingProfile $profile): Session
    {
        return Cashier::stripe()->checkout->sessions->create($profile->checkout_parameters, ['idempotency_key' => $profile->checkout_request_key]);
    }

    private function rememberCheckout(BillingProfile $profile, Session $session): void
    {
        if (! is_string($session->id) || ! is_string($session->url)) {
            throw new RuntimeException('Checkout was not confirmed.');
        }
        $profile->newQuery()->whereKey($profile->getKey())->where('checkout_request_key', $profile->checkout_request_key)
            ->whereNotNull('checkout_expires_at')->update(['checkout_session_id' => $session->id, 'checkout_url' => $session->url]);
    }

    private function checkoutLock(BillingProfile $profile): string
    {
        return 'billing-checkout:'.hash('sha256', $profile->getTable().':'.$profile->getKey());
    }

    public function syncFromWebhook(array $payload): void
    {
        $object = $payload['data']['object'] ?? [];
        $type = $payload['type'] ?? '';

        if (! in_array($type, [
            'checkout.session.completed', 'customer.subscription.created',
            'customer.subscription.updated', 'customer.subscription.deleted',
            'invoice.payment_failed', 'invoice.paid', 'invoice.payment_succeeded',
        ], true)) {
            return;
        }

        $id = str_starts_with($type, 'customer.subscription.')
            ? ($object['id'] ?? null)
            : ($object['subscription'] ?? $object['parent']['subscription_details']['subscription'] ?? null);

        if (! is_string($id) || $id === '') {
            return;
        }

        $current = Cashier::stripe()->subscriptions->retrieve($id, [])->toArray();
        $owner = $this->ownerFromCustomer($current['customer'] ?? null);
        if ($owner === null) {
            return;
        }

        $profile = $this->profileFor($owner);
        $profile->getConnection()->transaction(function () use ($owner, $current, $profile, $payload, $object, $type) {
            $profile = $profile->newQuery()->whereKey($profile->getKey())->lockForUpdate()->firstOrFail();
            $current = Cashier::stripe()->subscriptions->retrieve($current['id'], [])->toArray();
            $plan = $this->planForPrice($current['items']['data'][0]['price']['id'] ?? null);
            $existing = $this->subscriptionForOwner($owner);
            $metadata = $existing?->metadata ?? [];
            $incoming = (int) ($payload['created'] ?? 0);

            if (! empty($metadata['stripe_subscription_id'])
                && $metadata['stripe_subscription_id'] !== $current['id']
                && (int) ($current['created'] ?? 0) <= (int) ($metadata['stripe_subscription_created'] ?? 0)) {
                return;
            }

            $status = match ($current['status'] ?? '') {
                'active' => SubscriptionStatus::Active,
                'trialing' => SubscriptionStatus::Trialing,
                'past_due', 'unpaid', 'incomplete', 'paused' => SubscriptionStatus::PastDue,
                default => SubscriptionStatus::Canceled,
            };

            if ($plan === null) {
                Log::warning('Stripe subscription price is not mapped to a plan.', ['subscription_id' => $current['id']]);
                if ($existing === null) {
                    return;
                }
                if (in_array($status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing], true)) {
                    $status = SubscriptionStatus::PastDue;
                }
            }

            if ($type === 'invoice.payment_failed'
                && ($current['latest_invoice'] ?? null) === ($object['id'] ?? null)) {
                $invoice = Cashier::stripe()->invoices->retrieve($object['id'], [])->toArray();
                if (($invoice['status'] ?? null) !== 'paid') {
                    $status = SubscriptionStatus::PastDue;
                }
            }

            $start = $current['current_period_start'] ?? $current['items']['data'][0]['current_period_start'] ?? null;
            $end = $current['current_period_end'] ?? $current['items']['data'][0]['current_period_end'] ?? null;

            if ($status === SubscriptionStatus::Trialing) {
                $end = $current['trial_end'] ?? $end;
            }

            if ($end === null && in_array($status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing], true)) {
                throw new RuntimeException('The provider did not return an access expiry.');
            }

            $attributes = [
                'plan_id' => $plan?->getKey() ?? $existing->plan_id,
                'status' => $status,
                'current_period_start' => $start ? Carbon::createFromTimestampUTC($start) : null,
                'current_period_end' => $end ? Carbon::createFromTimestampUTC($end) : null,
                'metadata' => array_merge($metadata, [
                    'stripe_customer_id' => $current['customer'],
                    'stripe_subscription_id' => $current['id'],
                    'stripe_subscription_created' => (int) ($current['created'] ?? 0),
                    'stripe_event_created' => max($incoming, (int) ($metadata['stripe_event_created'] ?? 0)),
                    'cancel_at_period_end' => (bool) ($current['cancel_at_period_end'] ?? false),
                ]),
            ];

            if ($existing) {
                $existing->update($attributes);
            } else {
                $this->subscriptionModel()::create(array_merge($attributes, [
                    'owner_type' => $owner->getMorphClass(),
                    'owner_id' => $owner->getKey(),
                ]));
            }

            $profile->update([
                'checkout_expires_at' => null,
                'checkout_url' => null,
                'checkout_request_key' => null,
                'checkout_parameters' => null,
                'trial_claimed_at' => $profile->trial_claimed_at ?? (! empty($current['trial_start']) ? now() : null),
            ]);
        });
    }

    public function cancel(Subscription $subscription): void
    {
        $owner = $subscription->owner;

        $owner === null
            ? null
            : $this->profileFor($owner)->subscription('default')?->cancel();
    }

    private function planForPrice(?string $priceId): ?Plan
    {
        if ($priceId === null || $priceId === '') {
            return null;
        }

        return Plan::where('provider_price_id', $priceId)
            ->orWhereJsonContains('metadata->stripe_price_ids', $priceId)->first();
    }

    private function subscriptionForOwner(Model $owner): ?Subscription
    {
        return $this->subscriptionModel()::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->latest('id')->first();
    }

    private function profileFor(Model $owner): BillingProfile
    {
        $model = config('entitlements-cashier.billing_profile_model', BillingProfile::class);

        return $model::firstOrCreate([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
        ]);
    }

    private function ownerFromCustomer(?string $customerId): ?Model
    {
        if ($customerId === null) {
            return null;
        }

        $model = config('entitlements-cashier.billing_profile_model', BillingProfile::class);

        return $model::where('stripe_id', $customerId)->first()?->owner;
    }

    private function subscriptionModel(): string
    {
        return config('entitlements.models.subscription', Subscription::class);
    }

    private function returnUrl(string $status): string
    {
        $configured = config('entitlements-cashier.'.$status.'_url');

        return $configured ?: rtrim((string) config('app.url'), '/').'/billing?checkout='.($status === 'cancel' ? 'cancelled' : 'success');
    }

    protected function subscriptionFor(Model $owner, Plan $plan): SubscriptionBuilder
    {
        return $this->profileFor($owner)->newSubscription('default', $plan->provider_price_id);
    }

    /**
     * Days of trial a checkout grants, or null to charge straight away.
     *
     * Only a positive number is a trial. Zero, a negative, and anything that is
     * not a number at all mean the same as unset, because a zero day trial is
     * a trial that has already ended by the time the provider reads it.
     */
    protected function trialDays(): ?int
    {
        $days = config('entitlements-cashier.trial_days');

        // Asked before casting, because a cast answers for things that are not
        // numbers: true and [14] both come back as 1, which would be a trial
        // nobody configured.
        if (! is_numeric($days)) {
            return null;
        }

        return (int) $days > 0 ? (int) $days : null;
    }
}
