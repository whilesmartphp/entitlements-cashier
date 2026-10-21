<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('entitlements-cashier.profiles_table', 'billing_profiles'), function (Blueprint $table) {
            $table->uuid('checkout_request_key')->nullable();
            $table->json('checkout_parameters')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table(config('entitlements-cashier.profiles_table', 'billing_profiles'), fn (Blueprint $table) => $table->dropColumn(['checkout_request_key', 'checkout_parameters']));
    }
};
