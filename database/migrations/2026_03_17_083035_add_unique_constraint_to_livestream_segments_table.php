<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Resolve duplicates for (media_processing_log_id, segment_index) before adding the constraint
        $this->resolveDuplicates();

        Schema::table('livestream_segments', function (Blueprint $table): void {
            // 2. Add composite unique index on (media_processing_log_id, segment_index)
            $table->unique(
                ['media_processing_log_id', 'segment_index'],
                'livestream_segments_log_index_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('livestream_segments', function (Blueprint $table): void {
            // Drop composite unique
            $table->dropUnique('livestream_segments_log_index_unique');
        });
    }

    private function resolveDuplicates(): void
    {
        $duplicates = DB::table('livestream_segments')
            ->select('media_processing_log_id', 'segment_index')
            ->groupBy('media_processing_log_id', 'segment_index')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $records = DB::table('livestream_segments')
                ->where('media_processing_log_id', $duplicate->media_processing_log_id)
                ->where('segment_index', $duplicate->segment_index)
                ->orderBy('id')
                ->get();

            // Keep first, increment segment_index for others
            foreach ($records->skip(1) as $index => $record) {
                $newIndex = (int) $duplicate->segment_index + ($index + 1);

                // Ensure newIndex is also unique for this log
                while (DB::table('livestream_segments')
                    ->where('media_processing_log_id', $duplicate->media_processing_log_id)
                    ->where('segment_index', $newIndex)
                    ->exists()) {
                    $newIndex++;
                }

                DB::table('livestream_segments')
                    ->where('id', $record->id)
                    ->update(['segment_index' => $newIndex]);
            }
        }
    }
};
