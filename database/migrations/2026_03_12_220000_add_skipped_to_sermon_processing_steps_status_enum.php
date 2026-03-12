<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE sermon_processing_steps MODIFY COLUMN status ENUM('started', 'completed', 'skipped', 'failed', 'cancelled') NOT NULL"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE sermon_processing_steps MODIFY COLUMN status ENUM('started', 'completed', 'failed', 'cancelled') NOT NULL"
        );
    }
};
