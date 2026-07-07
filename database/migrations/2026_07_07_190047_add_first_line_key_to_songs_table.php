<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            // Non-unique: distinct songs can legitimately share a first line.
            $table->string('first_line_key')->nullable()->index()->after('canonical_key');
        });

        DB::table('songs')
            ->select(['id', 'lyrics_plain'])
            ->orderBy('id')
            ->chunkById(200, function ($songs): void {
                foreach ($songs as $song) {
                    $key = $this->firstLineKey($song->lyrics_plain);

                    if ($key !== null) {
                        DB::table('songs')->where('id', $song->id)->update(['first_line_key' => $key]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->dropIndex(['first_line_key']);
            $table->dropColumn('first_line_key');
        });
    }

    /**
     * First non-empty lyrics line, canonicalised the same way as
     * Song::canonicalizeKey() — inlined so the migration never depends on the
     * model's future shape.
     */
    private function firstLineKey(?string $lyricsPlain): ?string
    {
        if (! is_string($lyricsPlain) || trim($lyricsPlain) === '') {
            return null;
        }

        foreach (preg_split('/\r?\n/', $lyricsPlain) ?: [] as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            $normalised = trim(Str::lower($trimmed));
            $normalised = trim((string) preg_replace('/\s+/', ' ', $normalised));

            return $normalised === '' ? null : $normalised;
        }

        return null;
    }
};
