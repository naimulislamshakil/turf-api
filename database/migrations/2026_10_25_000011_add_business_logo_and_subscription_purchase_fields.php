<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('email');
        });

        Schema::table('manual_subscription_payments', function (Blueprint $table) {
            $table->unsignedSmallInteger('billing_periods')->default(1)->after('billing_cycle');
            $table->string('discount_type', 20)->nullable()->after('amount_paisa');
            $table->unsignedBigInteger('discount_value_basis_points')->default(0)->after('discount_type');
            $table->unsignedBigInteger('original_subtotal_paisa')->default(0)->after('discount_value_basis_points');
            $table->unsignedBigInteger('discount_amount_paisa')->default(0)->after('original_subtotal_paisa');
            $table->unsignedBigInteger('final_amount_paisa')->default(0)->after('discount_amount_paisa');
            $table->timestampTz('payment_confirmed_at')->nullable()->after('verification_at');
        });

        Schema::table('business_subscriptions', function (Blueprint $table) {
            $table->unsignedSmallInteger('billing_periods')->default(1)->after('billing_cycle');
        });
    }

    public function down(): void
    {
        Schema::table('business_subscriptions', fn (Blueprint $table) => $table->dropColumn('billing_periods'));
        Schema::table('manual_subscription_payments', function (Blueprint $table) {
            $table->dropColumn(['billing_periods', 'discount_type', 'discount_value_basis_points', 'original_subtotal_paisa', 'discount_amount_paisa', 'final_amount_paisa', 'payment_confirmed_at']);
        });
        Schema::table('businesses', fn (Blueprint $table) => $table->dropColumn('logo_path'));
    }
};
