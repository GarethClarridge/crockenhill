<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            // It's good practice to ensure the columns actually exist before trying to modify them,
            // though in this specific scenario, their problematic definition is the issue.

            // Before changing column definitions, it's often necessary to update existing
            // rows that have the problematic '0000-00-00 00:00:00' value if the column
            // is NOT NULL. However, making them NULLable first and then setting a
            // proper default is generally safer and more compatible.

            // The goal is to make them TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
            // Or TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP if your application logic requires NOT NULL.
            // Going with NULL for broader compatibility with potentially old/weird data.

            if (Schema::hasColumn('users', 'created_at')) {
                // For MySQL:
                // This attempts to change the column to be nullable and default to current timestamp.
                // If it's already NOT NULL and contains '0000-00-00...', this specific statement might fail.
                // A multi-step process (e.g., update data, then alter) is more robust for production.
                // But for fixing the definition to allow other alters, this is a common approach.
                DB::statement("ALTER TABLE users MODIFY COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP");
            }

            if (Schema::hasColumn('users', 'updated_at')) {
                // For MySQL:
                DB::statement("ALTER TABLE users MODIFY COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * Note: Reversing this precisely can be tricky if the original state was truly problematic.
     * For simplicity, we might just acknowledge they were changed.
     * If they were previously NOT NULL with an invalid default, simply dropping and re-adding
     * them according to the original (flawed) spec might not be desirable.
     */
    public function down(): void
    {
        // Schema::table('users', function (Blueprint $table) {
        //     // If you wanted to revert, you'd need to know the exact previous state.
        //     // For example, if they were NOT NULL with '0000-00-00...' default (which is invalid).
        //     // $table->timestamp('created_at')->nullable(false)->change(); // This might not set the old problematic default
        //     // $table->timestamp('updated_at')->nullable(false)->change();
        // });
        // Given the original issue, a simple down might not be fully restorative to the problematic state.
        // It's often safer to ensure the 'up' makes it valid and accept that as the new baseline.
    }
};
