<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PREACHER_FORMAT_CHECK = 'sermons_preacher_format_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // 1. Data Cleanup: Trim existing data
        DB::table('sermons')->update([
            'preacher' => DB::raw('TRIM(preacher)'),
        ]);

        // 2. Ensure no empty preacher names exist before adding constraint.
        // If it's empty (after trimming), use a generic fallback.
        DB::table('sermons')
            ->where('preacher', '')
            ->update(['preacher' => 'Unknown Preacher']);

        // 3. Add CHECK constraint
        // BINARY ensures exact character-for-character match for the trim check.
        DB::statement(sprintf(
            "ALTER TABLE sermons ADD CONSTRAINT %s CHECK (BINARY preacher = TRIM(preacher) AND preacher != '')",
            self::PREACHER_FORMAT_CHECK
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

        DB::statement(sprintf('ALTER TABLE sermons DROP CHECK %s', self::PREACHER_FORMAT_CHECK));
    }
};
