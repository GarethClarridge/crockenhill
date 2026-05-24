<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONSTRAINT_NAME = 'sermons_reference_format_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('sermons')) {
            return;
        }

        // Add CHECK constraint (MySQL 8.0.16+)
        // We do not modify existing data here per Warden standards;
        // if violations exist, the migration will fail loudly.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf(
                "ALTER TABLE sermons ADD CONSTRAINT %s CHECK (reference IS NULL OR (BINARY reference = TRIM(reference) AND reference != ''))",
                self::CONSTRAINT_NAME
            ));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql' && Schema::hasTable('sermons')) {
            DB::statement(sprintf('ALTER TABLE sermons DROP CHECK %s', self::CONSTRAINT_NAME));
        }
    }
};
