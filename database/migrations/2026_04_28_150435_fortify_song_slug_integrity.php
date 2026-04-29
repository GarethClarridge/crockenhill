<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Case-sensitive regex pattern for strict kebab-case slugs.
     * Pattern: lowercase alphanumeric only, hyphens allowed in middle.
     */
    private const SLUG_CHECK_PATTERN = '^[a-z0-9]+(?:-[a-z0-9]+)*$';

    private const CONSTRAINT_NAME = 'songs_slug_format_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('songs')) {
            return;
        }

        // 1. Backfill and repair invalid slugs
        // We use REGEXP with 'c' for case-sensitivity in PHP logic via model or DB::raw if needed,
        // but here we can just check if it matches our pattern.
        $songsToRepair = DB::table('songs')
            ->get(['id', 'title', 'slug']);

        $regex = '/'.self::SLUG_CHECK_PATTERN.'/';

        foreach ($songsToRepair as $song) {
            if ($song->slug !== null && $song->slug !== '' && preg_match($regex, $song->slug)) {
                continue;
            }

            // If slug is missing or invalid, generate a new one
            $baseSlug = Str::slug((string) ($song->slug ?: $song->title)) ?: 'untitled-song';
            $slug = $baseSlug;
            $counter = 1;

            // Collision avoidance (ignoring current record)
            while (DB::table('songs')->where('slug', $slug)->where('id', '!=', $song->id)->exists()) {
                $slug = $baseSlug.'-'.$counter;
                $counter++;
            }

            DB::table('songs')->where('id', $song->id)->update(['slug' => $slug]);
        }

        // 2. Modify slug column to be NOT NULL
        Schema::table('songs', function (Blueprint $table) {
            $table->string('slug', 255)->nullable(false)->change();
        });

        // 3. Add CHECK constraint for slug format (MySQL only)
        if (DB::getDriverName() === 'mysql') {
            // Wrapped in try-catch to avoid failing if it somehow already exists or if data fails check
            try {
                DB::statement(sprintf(
                    "ALTER TABLE songs ADD CONSTRAINT %s CHECK (REGEXP_LIKE(slug, '%s', 'c'))",
                    self::CONSTRAINT_NAME,
                    self::SLUG_CHECK_PATTERN
                ));
            } catch (\Illuminate\Database\QueryException $e) {
                // If it fails, log it or handle it. In this context, we'll let it bubble up
                // unless we want to be specifically defensive.
                throw $e;
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('songs')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf(
                'ALTER TABLE songs DROP CHECK %s',
                self::CONSTRAINT_NAME
            ));
        }

        Schema::table('songs', function (Blueprint $table) {
            $table->string('slug', 255)->nullable()->change();
        });
    }
};
