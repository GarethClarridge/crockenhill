<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Update invalid timestamp values to NULL
            DB::statement("UPDATE users SET created_at = NULL WHERE created_at = '0000-00-00 00:00:00' OR created_at < '1970-01-01'");
            DB::statement("UPDATE users SET updated_at = NULL WHERE updated_at = '0000-00-00 00:00:00' OR updated_at < '1970-01-01'");
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('created_at')->nullable()->change();
            $table->timestamp('updated_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('created_at')->nullable(false)->change();
            $table->timestamp('updated_at')->nullable(false)->change();
        });
    }
};
