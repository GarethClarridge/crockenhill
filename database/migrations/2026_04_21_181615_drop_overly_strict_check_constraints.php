<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // preacher_aliases_alias_format_check required lowercase-only aliases, but aliases are
        // natural-language alternative names (e.g. "Alternative Name") not slugs.
        if (Schema::hasTable('preacher_aliases')) {
            DB::statement('ALTER TABLE preacher_aliases DROP CHECK preacher_aliases_alias_format_check');
        }

    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (Schema::hasTable('preacher_aliases')) {
            DB::statement("ALTER TABLE preacher_aliases ADD CONSTRAINT preacher_aliases_alias_format_check CHECK (BINARY alias = LOWER(TRIM(alias)) AND alias != '')");
        }

    }
};
