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

        // 1. Clean up existing data to satisfy constraints before adding them.
        DB::table('users')->update([
            'name' => DB::raw('TRIM(name)'),
            'email' => DB::raw('LOWER(TRIM(email))'),
        ]);

        // 2. Add CHECK constraints.
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
