<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('credential_deliveries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('channel', 20);
            $table->string('destination_hash', 64);
            $table->string('status', 20)->default('queued')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error_code', 80)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'business_id', 'channel']);
            $table->index(['business_id', 'channel', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_deliveries');
    }
};
