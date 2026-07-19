<?php

namespace Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Cashier\CashierServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Whilesmart\Entitlements\EntitlementsServiceProvider;
use Whilesmart\EntitlementsCashier\EntitlementsCashierServiceProvider;
use Whilesmart\OwnerAccess\OwnerAccessServiceProvider;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        // The entitlements and this package's migrations load through their
        // providers; add a table for the test owner.
        Schema::create('owners', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    protected function getPackageProviders($app): array
    {
        return [
            OwnerAccessServiceProvider::class,
            CashierServiceProvider::class,
            EntitlementsServiceProvider::class,
            EntitlementsCashierServiceProvider::class,
        ];
    }
}
