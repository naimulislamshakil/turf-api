<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 120);
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->unsignedBigInteger('monthly_price_paisa');
            $table->unsignedBigInteger('yearly_price_paisa');
            $table->json('module_entitlements');
            $table->unsignedInteger('max_turfs')->nullable();
            $table->unsignedInteger('max_branches')->nullable();
            $table->unsignedInteger('max_staff')->nullable();
            $table->unsignedInteger('current_version')->default(1);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
