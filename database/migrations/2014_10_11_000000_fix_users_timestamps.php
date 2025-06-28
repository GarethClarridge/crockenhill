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
            // Step 1: Update existing '0000-00-00 00:00:00' dates to NOW()
            // This is important if the columns are currently NOT NULL or if such values exist.
            if (Schema::hasColumn('users', 'created_at')) {
                // Using CAST to char is a robust way to find 'zero dates'
                DB::statement("UPDATE users SET created_at = NOW() WHERE CAST(created_at AS CHAR(20)) = '0000-00-00 00:00:00'");
            }
            if (Schema::hasColumn('users', 'updated_at')) {
                DB::statement("UPDATE users SET updated_at = NOW() WHERE CAST(updated_at AS CHAR(20)) = '0000-00-00 00:00:00'");
            }

            // Step 2: Modify columns to be nullable first, then set the default.
            // This can help avoid issues if changing nullability and default in one step is problematic.
            if (Schema::hasColumn('users', 'created_at')) {
                DB::statement("ALTER TABLE users MODIFY COLUMN created_at TIMESTAMP NULL");
                DB::statement("ALTER TABLE users MODIFY COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP");
            }

            if (Schema::hasColumn('users', 'updated_at')) {
                DB::statement("ALTER TABLE users MODIFY COLUMN updated_at TIMESTAMP NULL");
                DB::statement("ALTER TABLE users MODIFY COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverting these changes to a potentially problematic state is complex and often not desired.
        // If absolutely necessary, one would need to know the exact prior column definitions.
        // For example, if they were NOT NULL and had an invalid default like '0000-00-00 00:00:00'.
        // Schema::table('users', function (Blueprint $table) {
        //     if (Schema::hasColumn('users', 'created_at')) {
        //         // $table->timestamp('created_at')->nullable(false)->change(); // This would require a valid default or existing data to be valid
        //     }
        //     if (Schema::hasColumn('users', 'updated_at')) {
        //         // $table->timestamp('updated_at')->nullable(false)->change();
        //     }
        // });
    }
};
