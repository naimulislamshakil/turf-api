<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('credential_deliveries', function (Blueprint $table) {
            $table->text('encrypted_payload')->nullable()->after('destination_hash');
        });
    }

    public function down(): void
    {
        Schema::table('credential_deliveries', fn (Blueprint $table) => $table->dropColumn('encrypted_payload'));
    }
};
