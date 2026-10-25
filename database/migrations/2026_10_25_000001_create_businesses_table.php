<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 160);
            $table->string('slug', 120)->unique();
            $table->string('phone', 40);
            $table->string('email')->nullable();
            $table->string('address', 500);
            $table->string('district_city', 120);
            $table->string('status', 30)->default('pending')->index();
            $table->foreignUlid('onboarding_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
