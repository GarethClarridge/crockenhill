<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // 1. Ensure existing data is normalized
        DB::table('preacher_aliases')->update([
            'alias' => DB::raw('LOWER(TRIM(alias))'),
        ]);

        // 2. Add the CHECK constraint if it doesn't already exist
        $constraintExists = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'preacher_aliases'
            AND CONSTRAINT_NAME = 'preacher_aliases_alias_format_check'
        ");

        if (empty($constraintExists)) {
            DB::statement("ALTER TABLE preacher_aliases ADD CONSTRAINT preacher_aliases_alias_format_check CHECK (BINARY alias = LOWER(TRIM(alias)) AND alias != '')");
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

        // MySQL 8.0.x does not support DROP CHECK IF EXISTS
        // We check manually to ensure rollback safety
        $constraintExists = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'preacher_aliases'
            AND CONSTRAINT_NAME = 'preacher_aliases_alias_format_check'
        ");

        if (! empty($constraintExists)) {
            DB::statement('ALTER TABLE preacher_aliases DROP CHECK preacher_aliases_alias_format_check');
        }
    }
};
