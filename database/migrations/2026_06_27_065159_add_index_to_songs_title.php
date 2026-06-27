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
        Schema::table('songs', function (Blueprint $table) {
            // Widen title to 255 to match project standards for title columns
            // and avoid validation/schema mismatch.
            $table->string('title', 255)->change();

            // Add index for search and sort performance
            $table->index('title');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->dropIndex(['title']);
        });

        // Truncate if necessary before narrowing back to 100
        DB::table('songs')
            ->whereRaw('LENGTH(title) > 100')
            ->update(['title' => DB::raw('LEFT(title, 100)')]);

        Schema::table('songs', function (Blueprint $table) {
            $table->string('title', 100)->change();
        });
    }
};
