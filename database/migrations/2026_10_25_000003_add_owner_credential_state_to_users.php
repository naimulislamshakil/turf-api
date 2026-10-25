<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 40)->nullable()->unique()->after('email');
            $table->timestamp('temporary_password_expires_at')->nullable()->index()->after('password');
            $table->boolean('must_change_password')->default(false)->index()->after('temporary_password_expires_at');
            $table->timestamp('password_changed_at')->nullable()->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->dropColumn(['phone', 'temporary_password_expires_at', 'must_change_password', 'password_changed_at']);
        });
    }
};
