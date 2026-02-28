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
        if (! Schema::hasTable('songs')) {
            return;
        }

        Schema::table('songs', function (Blueprint $table): void {
            if (! Schema::hasColumn('songs', 'canonical_key')) {
                $table->string('canonical_key')->nullable();
            }

            if (! Schema::hasColumn('songs', 'title')) {
                $table->string('title')->nullable();
            }

            if (! Schema::hasColumn('songs', 'alternate_title')) {
                $table->string('alternate_title')->nullable();
            }

            if (! Schema::hasColumn('songs', 'lyrics_xml')) {
                $table->longText('lyrics_xml')->nullable();
            }

            if (! Schema::hasColumn('songs', 'lyrics_plain')) {
                $table->longText('lyrics_plain')->nullable();
            }

            if (! Schema::hasColumn('songs', 'verse_order')) {
                $table->string('verse_order')->nullable();
            }

            if (! Schema::hasColumn('songs', 'copyright')) {
                $table->string('copyright')->nullable();
            }

            if (! Schema::hasColumn('songs', 'comments')) {
                $table->longText('comments')->nullable();
            }

            if (! Schema::hasColumn('songs', 'ccli_number')) {
                $table->string('ccli_number')->nullable();
            }

            if (! Schema::hasColumn('songs', 'import_metadata')) {
                $table->json('import_metadata')->nullable();
            }

            if (! Schema::hasColumn('songs', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }

            if (! Schema::hasColumn('songs', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }

            if (! Schema::hasColumn('songs', 'deleted_at')) {
                $table->timestamp('deleted_at')->nullable();
            }
        });

        $this->normalizeSongsRows();
        $this->ensureSongsIndexes();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally non-destructive: this migration reconciles legacy schemas in-place.
    }

    private function normalizeSongsRows(): void
    {
        if (! Schema::hasColumn('songs', 'id') || ! Schema::hasColumn('songs', 'canonical_key')) {
            return;
        }

        $rows = DB::table('songs')
            ->select(['id', 'canonical_key'])
            ->orderBy('id')
            ->get();

        $seenCanonicalKeys = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $canonicalKey = $this->canonicalKeyOrFallback($row->canonical_key, $id);

            if (isset($seenCanonicalKeys[$canonicalKey])) {
                $canonicalKey = $this->uniqueCanonicalKey($canonicalKey, $id, $seenCanonicalKeys);
            }

            $seenCanonicalKeys[$canonicalKey] = true;

            if ($row->canonical_key !== $canonicalKey) {
                DB::table('songs')
                    ->where('id', $id)
                    ->update(['canonical_key' => $canonicalKey]);
            }
        }

        if (Schema::hasColumn('songs', 'title')) {
            $missingTitleRows = DB::table('songs')
                ->select(['id'])
                ->where(function ($query): void {
                    $query->whereNull('title')
                        ->orWhere('title', '');
                })
                ->orderBy('id')
                ->get();

            foreach ($missingTitleRows as $row) {
                DB::table('songs')
                    ->where('id', (int) $row->id)
                    ->update(['title' => 'Legacy Song '.(int) $row->id]);
            }
        }

        if (Schema::hasColumn('songs', 'lyrics_xml')) {
            DB::table('songs')
                ->whereNull('lyrics_xml')
                ->update(['lyrics_xml' => '']);
        }
    }

    private function ensureSongsIndexes(): void
    {
        Schema::table('songs', function (Blueprint $table): void {
            if (
                Schema::hasColumn('songs', 'canonical_key')
                && ! Schema::hasIndex('songs', ['canonical_key'], 'unique')
            ) {
                $table->unique('canonical_key');
            }

            if (
                Schema::hasColumn('songs', 'ccli_number')
                && ! Schema::hasIndex('songs', ['ccli_number'])
            ) {
                $table->index('ccli_number');
            }

            if (
                Schema::hasColumn('songs', 'deleted_at')
                && ! Schema::hasIndex('songs', ['deleted_at'])
            ) {
                $table->index('deleted_at');
            }
        });
    }

    private function canonicalKeyOrFallback(mixed $canonicalKey, int $id): string
    {
        if (! is_string($canonicalKey)) {
            return 'legacy-song-'.$id;
        }

        $trimmedCanonicalKey = trim($canonicalKey);

        if ($trimmedCanonicalKey === '') {
            return 'legacy-song-'.$id;
        }

        return $trimmedCanonicalKey;
    }

    /**
     * @param  array<string, bool>  $seenCanonicalKeys
     */
    private function uniqueCanonicalKey(string $canonicalKey, int $id, array $seenCanonicalKeys): string
    {
        $candidate = $canonicalKey.'-legacy-'.$id;
        $suffix = 2;

        while (isset($seenCanonicalKeys[$candidate])) {
            $candidate = $canonicalKey.'-legacy-'.$id.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
};
