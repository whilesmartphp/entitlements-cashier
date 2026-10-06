<?php

namespace Whilesmart\EntitlementsCashier;

use Illuminate\Database\Eloquent\Model;
use Whilesmart\Entitlements\Contracts\PlanSource;
use Whilesmart\Entitlements\Models\Plan;
use Whilesmart\Entitlements\Support\EloquentPlanSource;
use Whilesmart\Entitlements\Support\ResolvedPlan;

class DefaultPlanSource implements PlanSource
{
    public function __construct(private EloquentPlanSource $subscribed) {}

    public function resolve(?Model $owner): ?ResolvedPlan
    {
        $subscribed = $this->subscribed->resolve($owner);
        if ($subscribed !== null) {
            return $subscribed;
        }

        $key = config('entitlements-cashier.default_plan');
        $model = config('entitlements.models.plan', Plan::class);
        $plan = filled($key) ? $model::where('key', $key)->first() : null;

        return $plan === null ? null : ResolvedPlan::of($plan->features ?? [], $plan->limits ?? [], $plan->meters ?? []);
    }
}
