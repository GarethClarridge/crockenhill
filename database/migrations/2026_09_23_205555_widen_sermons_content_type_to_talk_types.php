<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            "ALTER TABLE sermons MODIFY COLUMN content_type ENUM('sermon', 'childrens_talk', 'partner_update', 'testimony') NOT NULL DEFAULT 'sermon'"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (DB::table('sermons')->whereIn('content_type', ['partner_update', 'testimony'])->exists()) {
            throw new RuntimeException('Cannot narrow sermons.content_type while partner updates or testimonies exist.');
        }

        DB::statement(
            "ALTER TABLE sermons MODIFY COLUMN content_type ENUM('sermon', 'childrens_talk') NOT NULL DEFAULT 'sermon'"
        );
    }
};
