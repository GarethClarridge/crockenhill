<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DESCRIPTION_FORMAT_CHECK = 'pages_description_format_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // 1. Data Cleanup: Trim existing data
        DB::table('pages')->update([
            'description' => DB::raw('TRIM(description)'),
        ]);

        // 2. Ensure no empty descriptions exist before adding constraint.
        // Fallback to heading if description is empty or whitespace-only.
        DB::table('pages')
            ->where('description', '')
            ->update(['description' => DB::raw('heading')]);

        // If it's STILL empty (heading was also empty), use a generic fallback.
        DB::table('pages')
            ->where('description', '')
            ->update(['description' => 'No description provided.']);

        // 3. Add CHECK constraint
        // BINARY ensures exact character-for-character match for the trim check.
        // The constraint implicitly handles NULL because NULL = TRIM(NULL) is UNKNOWN (passes).
        // However, the column is already NOT NULL in the schema.
        DB::statement(sprintf(
            "ALTER TABLE pages ADD CONSTRAINT %s CHECK (BINARY description = TRIM(description) AND description != '')",
            self::DESCRIPTION_FORMAT_CHECK
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

        DB::statement(sprintf('ALTER TABLE pages DROP CHECK %s', self::DESCRIPTION_FORMAT_CHECK));
    }
};
