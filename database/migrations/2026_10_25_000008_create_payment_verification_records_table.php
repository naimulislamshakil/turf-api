<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_verification_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('manual_subscription_payment_id')->constrained('manual_subscription_payments')->cascadeOnDelete();
            $table->foreignUlid('platform_admin_id')->constrained('platform_admins');
            $table->string('action', 20);
            $table->timestampTz('verified_at');
            $table->string('rejection_reason', 1000)->nullable();
            $table->timestampsTz();
            $table->index(['manual_subscription_payment_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_verification_records');
    }
};
