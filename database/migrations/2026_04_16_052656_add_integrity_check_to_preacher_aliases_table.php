<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
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

        // 1. Ensure existing data is normalized before adding the constraint
        DB::table('preacher_aliases')->update([
            'alias' => DB::raw('LOWER(TRIM(alias))'),
        ]);

        // 2. Add the CHECK constraint
        // Ensures alias is lowercase, has no leading/trailing whitespace, and is not empty.
        // We use BINARY to ensure the check is case-sensitive even on case-insensitive collations.
        // Wrapped in try-catch: existing data may violate the constraint (handled by subsequent migration).
        try {
            DB::statement("ALTER TABLE preacher_aliases ADD CONSTRAINT preacher_aliases_alias_format_check CHECK (BINARY alias = LOWER(TRIM(alias)) AND alias != '')");
        } catch (QueryException) {
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

        DB::statement('ALTER TABLE preacher_aliases DROP CHECK preacher_aliases_alias_format_check');
    }
};
