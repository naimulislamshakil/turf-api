<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('credential_deliveries', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'business_id', 'channel']);
            $table->index(['user_id', 'business_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::table('credential_deliveries', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'business_id', 'channel']);
            $table->unique(['user_id', 'business_id', 'channel']);
        });
    }
};
