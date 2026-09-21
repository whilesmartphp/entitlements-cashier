<?php

namespace Whilesmart\EntitlementsCashier;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Relations\Relation;
use Laravel\Cashier\SubscriptionBuilder;
use RuntimeException;
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

        $reference = [
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => (string) $owner->getKey(),
            'plan_key' => $plan->key,
        ];

        $checkout = $this->subscriptionFor($owner, $plan)->checkout([
            'success_url' => $this->returnUrl('success'),
            'cancel_url' => $this->returnUrl('cancel'),
            'metadata' => $reference,
            'subscription_data' => ['metadata' => $reference],
        ]);

        return $checkout->url;
    }

    public function syncFromWebhook(array $payload): void
    {
        $object = $payload['data']['object'] ?? [];

        match ($payload['type'] ?? null) {
            'checkout.session.completed' => $this->onCheckoutCompleted($object),
            'customer.subscription.updated' => $this->onSubscriptionChanged($object),
            'customer.subscription.deleted' => $this->onSubscriptionDeleted($object),
            default => null,
        };
    }

    public function cancel(Subscription $subscription): void
    {
        $owner = $subscription->owner;

        $owner === null
            ? null
            : $this->profileFor($owner)->subscription('default')?->cancel();
    }

    private function onCheckoutCompleted(array $session): void
    {
        $owner = $this->ownerFromReference($session['metadata'] ?? []);
        $plan = Plan::where('key', $session['metadata']['plan_key'] ?? null)->first();

        if ($owner !== null && $plan !== null) {
            $this->activate($owner, $plan, [
                'stripe_customer_id' => $session['customer'] ?? null,
                'stripe_subscription_id' => $session['subscription'] ?? null,
            ]);
        }
    }

    private function onSubscriptionChanged(array $subscription): void
    {
        $owner = $this->ownerFromCustomer($subscription['customer'] ?? null);
        $plan = Plan::where('provider_price_id', $subscription['items']['data'][0]['price']['id'] ?? null)->first();

        if ($owner !== null && $plan !== null && in_array($subscription['status'] ?? '', ['active', 'trialing'], true)) {
            $this->activate($owner, $plan, [
                'stripe_customer_id' => $subscription['customer'] ?? null,
                'stripe_subscription_id' => $subscription['id'] ?? null,
            ]);
        }
    }

    private function onSubscriptionDeleted(array $subscription): void
    {
        $owner = $this->ownerFromCustomer($subscription['customer'] ?? null);

        if ($owner === null) {
            return;
        }

        $fallback = config('entitlements-cashier.default_plan');
        $plan = $fallback ? Plan::where('key', $fallback)->first() : null;

        if ($plan !== null) {
            $this->activate($owner, $plan, ['stripe_subscription_id' => null]);

            return;
        }

        $this->activeSubscriptionFor($owner)?->update(['status' => SubscriptionStatus::Canceled]);
    }

    /**
     * Point the owner's entitlement subscription at a plan, keeping one row.
     */
    private function activate(Model $owner, Plan $plan, array $meta): void
    {
        $existing = $this->activeSubscriptionFor($owner);
        $metadata = array_merge($existing?->metadata ?? [], array_filter($meta, fn ($v) => $v !== null));

        if ($existing !== null) {
            $existing->update([
                'plan_id' => $plan->getKey(),
                'status' => SubscriptionStatus::Active,
                'metadata' => $metadata ?: null,
            ]);

            return;
        }

        $this->subscriptionModel()::create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'plan_id' => $plan->getKey(),
            'status' => SubscriptionStatus::Active,
            'metadata' => $metadata ?: null,
        ]);
    }

    private function activeSubscriptionFor(Model $owner): ?Subscription
    {
        return $this->subscriptionModel()::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->active()
            ->latest('id')
            ->first();
    }

    private function profileFor(Model $owner): BillingProfile
    {
        $model = config('entitlements-cashier.billing_profile_model', BillingProfile::class);

        return $model::firstOrCreate([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
        ]);
    }

    private function ownerFromReference(array $metadata): ?Model
    {
        $type = $metadata['owner_type'] ?? null;
        $id = $metadata['owner_id'] ?? null;

        if ($type === null || $id === null) {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        return class_exists($class) ? $class::find($id) : null;
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

        return $configured ?: rtrim((string) config('app.url'), '/').'/billing/'.$status;
    }

    /**
     * The subscription a checkout is started from, with any trial applied.
     */
    protected function subscriptionFor(Model $owner, Plan $plan): SubscriptionBuilder
    {
        $subscription = $this->profileFor($owner)->newSubscription('default', $plan->provider_price_id);

        if (($days = $this->trialDays()) !== null) {
            // To the end of that day, not to this moment on it. Stripe is sent
            // an instant and floors the difference when it says how many days
            // are free, so a trial set to exactly now plus fourteen days reads
            // as thirteen by the time the page is open.
            $subscription->trialUntil(Carbon::now()->addDays($days)->endOfDay());
        }

        return $subscription;
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
