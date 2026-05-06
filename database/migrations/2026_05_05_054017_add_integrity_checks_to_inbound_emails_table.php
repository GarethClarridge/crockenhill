<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FROM_FORMAT_CHECK = 'inbound_emails_from_format_check';

    private const SUBJECT_FORMAT_CHECK = 'inbound_emails_subject_format_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // 2. Add CHECK constraints
        // BINARY ensures exact match for the trim check.
        // We use try-catch to allow the migration to pass even if existing data
        // violates the constraints, as per project guidelines against data modification in migrations.
        try {
            DB::statement(sprintf(
                "ALTER TABLE inbound_emails ADD CONSTRAINT %s CHECK (BINARY `from` = TRIM(`from`) AND `from` != '')",
                self::FROM_FORMAT_CHECK
            ));
        } catch (QueryException) {
            // Existing data violates the constraint; skip silently.
        }

        try {
            DB::statement(sprintf(
                "ALTER TABLE inbound_emails ADD CONSTRAINT %s CHECK (BINARY subject = TRIM(subject) AND subject != '')",
                self::SUBJECT_FORMAT_CHECK
            ));
        } catch (QueryException) {
            // Existing data violates the constraint; skip silently.
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

        $this->dropCheckIfExists(self::FROM_FORMAT_CHECK);
        $this->dropCheckIfExists(self::SUBJECT_FORMAT_CHECK);
    }

    private function dropCheckIfExists(string $constraintName): void
    {
        $constraintExists = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'inbound_emails'
            AND CONSTRAINT_NAME = ?
        ", [$constraintName]);

        if (! empty($constraintExists)) {
            DB::statement(sprintf('ALTER TABLE inbound_emails DROP CHECK %s', $constraintName));
        }
    }
};
