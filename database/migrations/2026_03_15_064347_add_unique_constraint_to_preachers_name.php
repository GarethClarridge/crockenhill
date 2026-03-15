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
        // 1. Resolve duplicates for name before adding the constraint
        $this->resolveDuplicates();

        Schema::table('preachers', function (Blueprint $table) {
            // 2. Add unique index on name
            $table->unique('name', 'preachers_name_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('preachers', function (Blueprint $table) {
            // Drop unique index
            $table->dropUnique('preachers_name_unique');
        });
    }

    private function resolveDuplicates(): void
    {
        $duplicates = DB::table('preachers')
            ->select('name')
            ->groupBy('name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $records = DB::table('preachers')
                ->where('name', $duplicate->name)
                ->orderBy('id')
                ->get();

            // Keep first, rename others
            foreach ($records->skip(1) as $index => $record) {
                $newName = $duplicate->name.' ('.($index + 2).')';

                // Ensure newName is also unique
                $counter = 2;
                while (DB::table('preachers')->where('name', $newName)->exists()) {
                    $counter++;
                    $newName = $duplicate->name.' ('.$counter.')';
                }

                DB::table('preachers')
                    ->where('id', $record->id)
                    ->update(['name' => $newName]);
            }
        }
    }
};
