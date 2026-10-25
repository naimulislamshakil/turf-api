<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('business_subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUlid('subscription_plan_version_id')->constrained('subscription_plan_versions');
            $table->foreignUlid('manual_subscription_payment_id')->constrained('manual_subscription_payments');
            $table->string('billing_cycle', 20);
            $table->unsignedBigInteger('price_paisa');
            $table->char('currency', 3)->default('BDT');
            $table->string('status', 20)->default('active')->index();
            $table->timestampTz('starts_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('activated_at');
            $table->timestampsTz();
            $table->unique('manual_subscription_payment_id');
            $table->index(['business_id', 'status']);
        });

        DB::statement("CREATE UNIQUE INDEX business_subscriptions_one_active_idx ON business_subscriptions (business_id) WHERE status = 'active'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS business_subscriptions_one_active_idx');
        Schema::dropIfExists('business_subscriptions');
    }
};
