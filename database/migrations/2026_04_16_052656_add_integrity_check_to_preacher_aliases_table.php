<?php

declare(strict_types=1);

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
        DB::statement("ALTER TABLE preacher_aliases ADD CONSTRAINT preacher_aliases_alias_format_check CHECK (BINARY alias = LOWER(TRIM(alias)) AND alias != '')");
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
