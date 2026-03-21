<?php

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
        // 1. Resolve duplicates for page_id before adding the constraint
        $this->resolveDuplicates();

        Schema::table('meetings', function (Blueprint $table) {
            $table->unique('page_id', 'meetings_page_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropUnique('meetings_page_id_unique');
        });
    }

    private function resolveDuplicates(): void
    {
        $duplicates = DB::table('meetings')
            ->select('page_id')
            ->whereNotNull('page_id')
            ->groupBy('page_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $records = DB::table('meetings')
                ->where('page_id', $duplicate->page_id)
                ->orderBy('id')
                ->get();

            // Keep first, set others to NULL (safe fallback as it's a nullable foreign key)
            foreach ($records->skip(1) as $record) {
                DB::table('meetings')
                    ->where('id', $record->id)
                    ->update(['page_id' => null]);
            }
        }
    }
};
