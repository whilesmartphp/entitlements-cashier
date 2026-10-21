<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('entitlements-cashier.profiles_table', 'billing_profiles'), function (Blueprint $table) {
            $table->timestamp('trial_claimed_at')->nullable();
            $table->string('checkout_session_id')->nullable();
            $table->text('checkout_url')->nullable();
            $table->timestamp('checkout_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table(config('entitlements-cashier.profiles_table', 'billing_profiles'), fn (Blueprint $table) => $table->dropColumn([
            'trial_claimed_at', 'checkout_session_id', 'checkout_url', 'checkout_expires_at',
        ]));
    }
};
