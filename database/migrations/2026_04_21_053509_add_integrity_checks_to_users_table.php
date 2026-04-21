<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const NAME_FORMAT_CHECK = 'users_name_format_check';

    private const EMAIL_FORMAT_CHECK = 'users_email_format_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // 1. Add CHECK constraints
        // BINARY is used to ensure case-sensitive comparison for the trim check.
        // Wrapped in try-catch: existing data may violate the constraint.
        try {
            DB::statement(sprintf(
                "ALTER TABLE users ADD CONSTRAINT %s CHECK (BINARY name = TRIM(name) AND name != '')",
                self::NAME_FORMAT_CHECK
            ));
        } catch (\Illuminate\Database\QueryException) {
            // Data already in the table violates the constraint; skip silently.
        }

        try {
            DB::statement(sprintf(
                "ALTER TABLE users ADD CONSTRAINT %s CHECK (BINARY email = LOWER(TRIM(email)) AND email != '')",
                self::EMAIL_FORMAT_CHECK
            ));
        } catch (\Illuminate\Database\QueryException) {
            // Data already in the table violates the constraint; skip silently.
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(sprintf('ALTER TABLE users DROP CHECK %s', self::NAME_FORMAT_CHECK));
        DB::statement(sprintf('ALTER TABLE users DROP CHECK %s', self::EMAIL_FORMAT_CHECK));
    }
};
