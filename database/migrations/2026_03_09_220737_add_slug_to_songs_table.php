<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('canonical_key');
        });

        // Backfill slugs from existing titles.
        $songs = DB::table('songs')->whereNull('deleted_at')->get(['id', 'title']);

        $usedSlugs = [];

        foreach ($songs as $song) {
            $base = Str::slug($song->title);
            $slug = $base;
            $suffix = 2;

            while (in_array($slug, $usedSlugs, true)) {
                $slug = $base.'-'.$suffix;
                $suffix++;
            }

            $usedSlugs[] = $slug;

            DB::table('songs')->where('id', $song->id)->update(['slug' => $slug]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->dropColumn('slug');
        });
    }
};
