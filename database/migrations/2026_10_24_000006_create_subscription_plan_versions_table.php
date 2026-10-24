<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('subscription_plan_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('subscription_plan_id')->constrained('subscription_plans')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('name', 120);
            $table->string('slug', 100);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('monthly_price_paisa');
            $table->unsignedBigInteger('yearly_price_paisa');
            $table->json('module_entitlements');
            $table->unsignedInteger('max_turfs')->nullable();
            $table->unsignedInteger('max_branches')->nullable();
            $table->unsignedInteger('max_staff')->nullable();
            $table->foreignUlid('published_by_platform_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('published_at');
            $table->timestamps();
            $table->unique(['subscription_plan_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plan_versions');
    }
};
