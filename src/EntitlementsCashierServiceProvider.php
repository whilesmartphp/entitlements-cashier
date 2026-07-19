<?php

namespace Whilesmart\EntitlementsCashier;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookReceived;
use Whilesmart\Entitlements\Contracts\BillingProvider;
use Whilesmart\EntitlementsCashier\Listeners\SyncEntitlementsFromWebhook;
use Whilesmart\EntitlementsCashier\Models\BillingProfile;

class EntitlementsCashierServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/entitlements-cashier.php', 'entitlements-cashier');

        // Fulfil the entitlements checkout seam. bind() overrides the package's
        // NullBillingProvider default regardless of provider load order.
        $this->app->bind(BillingProvider::class, CashierBillingProvider::class);
    }

    public function boot(): void
    {
        Cashier::useCustomerModel(config('entitlements-cashier.billing_profile_model', BillingProfile::class));

        Event::listen(WebhookReceived::class, SyncEntitlementsFromWebhook::class);

        if (config('entitlements-cashier.register_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        $this->publishes([
            __DIR__.'/../config/entitlements-cashier.php' => config_path('entitlements-cashier.php'),
        ], 'entitlements-cashier-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'entitlements-cashier-migrations');
    }
}
