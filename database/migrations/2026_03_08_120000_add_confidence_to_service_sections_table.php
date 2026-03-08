<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('service_sections', 'confidence')) {
            Schema::table('service_sections', function (Blueprint $table): void {
                $table->decimal('confidence', 4, 3)
                    ->nullable()
                    ->after('metadata');
            });
        }

        DB::table('service_sections')
            ->select(['id', 'metadata'])
            ->orderBy('id')
            ->chunkById(200, function ($sections): void {
                foreach ($sections as $section) {
                    $metadata = json_decode((string) ($section->metadata ?? 'null'), true);
                    $level = is_array($metadata) ? ($metadata['confidence_level'] ?? null) : null;

                    $confidence = match ($level) {
                        'high' => 0.90,
                        'low' => 0.50,
                        'none' => 0.10,
                        default => 0.10,
                    };

                    DB::table('service_sections')
                        ->where('id', $section->id)
                        ->update(['confidence' => $confidence]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('service_sections', 'confidence')) {
            Schema::table('service_sections', function (Blueprint $table): void {
                $table->dropColumn('confidence');
            });
        }
    }
};
