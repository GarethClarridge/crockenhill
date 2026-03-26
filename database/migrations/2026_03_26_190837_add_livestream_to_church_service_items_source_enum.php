<?php

declare(strict_types=1);

use App\Enums\ChurchServiceItemSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('church_service_items') || ! Schema::hasColumn('church_service_items', 'source')) {
            return;
        }

        $enumValues = implode("','", ChurchServiceItemSource::values());

        DB::statement("ALTER TABLE church_service_items MODIFY COLUMN source ENUM('{$enumValues}') NULL");
    }

    public function down(): void
    {
        if (! Schema::hasTable('church_service_items') || ! Schema::hasColumn('church_service_items', 'source')) {
            return;
        }

        $oldValues = implode("','", ['email', 'openlp', 'manual']);

        DB::statement("ALTER TABLE church_service_items MODIFY COLUMN source ENUM('{$oldValues}') NULL");
    }
};
