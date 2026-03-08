<?php

declare(strict_types=1);

use App\Enums\ChurchServiceItemSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('church_service_items') || Schema::hasColumn('church_service_items', 'source')) {
            return;
        }

        Schema::table('church_service_items', function (Blueprint $table): void {
            $table->enum('source', ChurchServiceItemSource::values())
                ->nullable()
                ->after('type');
        });

        DB::table('church_service_items')->update([
            'source' => ChurchServiceItemSource::OPENLP->value,
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('church_service_items') || ! Schema::hasColumn('church_service_items', 'source')) {
            return;
        }

        Schema::table('church_service_items', function (Blueprint $table): void {
            $table->dropColumn('source');
        });
    }
};
