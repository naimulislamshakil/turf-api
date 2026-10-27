<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('credential_deliveries', function (Blueprint $table) {
            $table->uuid('claim_token')->nullable()->after('status');
            $table->timestampTz('claimed_at')->nullable()->after('claim_token');
            $table->uuid('credential_generation')->nullable()->after('business_id');
        });
    }

    public function down(): void
    {
        Schema::table('credential_deliveries', function (Blueprint $table) {
            $table->dropColumn(['claim_token', 'claimed_at', 'credential_generation']);
        });
    }
};
