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

        // 1. Data Cleanup: Normalize existing data before adding constraints
        DB::table('users')->update([
            'name' => DB::raw('TRIM(name)'),
            'email' => DB::raw('LOWER(TRIM(email))'),
        ]);

        // Remove any records with empty names/emails if they exist (they shouldn't in production, but good for safety)
        // Actually, better to just let it fail if there is bad data that can't be automatically fixed,
        // but here we can at least ensure they aren't just whitespace.

        // 2. Add CHECK constraints
        // BINARY is used to ensure case-sensitive comparison for the trim check.
        DB::statement(sprintf(
            "ALTER TABLE users ADD CONSTRAINT %s CHECK (BINARY name = TRIM(name) AND name != '')",
            self::NAME_FORMAT_CHECK
        ));

        DB::statement(sprintf(
            "ALTER TABLE users ADD CONSTRAINT %s CHECK (BINARY email = LOWER(TRIM(email)) AND email != '')",
            self::EMAIL_FORMAT_CHECK
        ));
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
