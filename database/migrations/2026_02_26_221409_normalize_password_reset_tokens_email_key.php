<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('password_reset_tokens') || ! Schema::hasColumn('password_reset_tokens', 'email')) {
            return;
        }

        if (DB::getDriverName() === 'mysql' && $this->hasPrimaryKeyOnEmail()) {
            DB::statement('ALTER TABLE password_reset_tokens DROP PRIMARY KEY');
        }

        if (! Schema::hasIndex('password_reset_tokens', 'password_reset_tokens_email_index')) {
            Schema::table('password_reset_tokens', function (Blueprint $table): void {
                $table->index('email', 'password_reset_tokens_email_index');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally non-destructive: this migration normalizes drifted schemas.
    }

    private function hasPrimaryKeyOnEmail(): bool
    {
        $primaryKeyOnEmail = DB::table('information_schema.key_column_usage')
            ->select('constraint_name')
            ->whereRaw('table_schema = database()')
            ->where('table_name', 'password_reset_tokens')
            ->where('column_name', 'email')
            ->where('constraint_name', 'PRIMARY')
            ->exists();

        return $primaryKeyOnEmail;
    }
};
