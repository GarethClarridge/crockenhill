<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `songs` carried two alternate-title columns. `alternate_title` is the one the
 * OpenLP sync writes and `SongTitleResolver` indexes; `alternative_title` is a
 * legacy twin that no code writes and no row populates — 0 of 1,160 live songs.
 * `LegacySongReconciler` already guards its only read with `Schema::hasColumn()`,
 * so it is written to survive this removal.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('songs', 'alternative_title')) {
            return;
        }

        Schema::table('songs', function (Blueprint $table): void {
            $table->dropColumn('alternative_title');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('songs', 'alternative_title')) {
            return;
        }

        Schema::table('songs', function (Blueprint $table): void {
            $table->string('alternative_title', 100)->nullable()->after('author');
        });
    }
};
