<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'is_admin')) {
                // Add is_admin after the 'password' column if it exists,
                // otherwise add it as one of the last columns.
                // This is a sensible default placement.
                if (Schema::hasColumn('users', 'password')) {
                    $table->boolean('is_admin')->default(false)->after('password');
                } else {
                    $table->boolean('is_admin')->default(false);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'is_admin')) {
                $table->dropColumn('is_admin');
            }
        });
    }
};
