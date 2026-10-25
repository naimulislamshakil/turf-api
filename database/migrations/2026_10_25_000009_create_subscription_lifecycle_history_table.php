<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('subscription_lifecycle_history', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_subscription_id')->constrained('business_subscriptions')->cascadeOnDelete();
            $table->string('status', 20);
            $table->string('action', 50);
            $table->foreignUlid('platform_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampsTz();
            $table->index(['business_subscription_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_lifecycle_history');
    }
};
