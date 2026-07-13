<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STATUS_PUBLICATION_CHECK = 'service_sections_status_publication_check';

    public function up(): void
    {
        if (! Schema::hasColumn('service_sections', 'status')) {
            return;
        }

        if (DB::table('service_sections')->where('status', 'skipped')->exists()) {
            throw new RuntimeException('Cannot remove the skipped service-section status while matching rows exist.');
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE service_sections DROP CHECK '.self::STATUS_PUBLICATION_CHECK);
        DB::statement("ALTER TABLE service_sections MODIFY COLUMN status ENUM('identified') NOT NULL");
    }

    public function down(): void
    {
        if (! Schema::hasColumn('service_sections', 'status') || DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE service_sections MODIFY COLUMN status ENUM('identified', 'skipped') NOT NULL");
        DB::statement(
            'ALTER TABLE service_sections ADD CONSTRAINT '.self::STATUS_PUBLICATION_CHECK.' CHECK ('.
            "(status <> 'skipped') OR publication_status = 'not_applicable'".
            ')'
        );
    }
};
