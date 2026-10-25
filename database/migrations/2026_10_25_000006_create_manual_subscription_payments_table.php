<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('manual_subscription_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUlid('subscription_plan_version_id')->constrained('subscription_plan_versions');
            $table->string('billing_cycle', 20);
            $table->unsignedBigInteger('amount_paisa');
            $table->char('currency', 3)->default('BDT');
            $table->string('payment_method', 30);
            $table->string('transaction_reference', 160);
            $table->timestampTz('payment_date');
            $table->string('status', 30)->default('pending_verification')->index();
            $table->foreignUlid('recorded_by_platform_admin_id')->constrained('platform_admins');
            $table->foreignUlid('verified_by_platform_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestampTz('verification_at')->nullable();
            $table->string('rejection_reason', 1000)->nullable();
            $table->timestampsTz();
            $table->unique(['payment_method', 'transaction_reference']);
            $table->index(['business_id', 'status']);
            $table->index(['subscription_plan_version_id', 'billing_cycle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_subscription_payments');
    }
};
