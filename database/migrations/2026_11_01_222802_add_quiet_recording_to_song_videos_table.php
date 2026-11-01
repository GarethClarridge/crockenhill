<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether part of a song clip was recorded very quietly, so wherever the clip is shown can say
 * so: the song counterpart of `sermons.partial_recording` (register E25, 11-01). 1011's hymn
 * opens on a solo voice about 40 dB below the congregation and band, and the operator ruled it
 * published whole, "but we should flag it, as we do for broken talk recordings". Set by the
 * pipeline when a start is taken over singing far quieter than the song, or by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('song_videos', function (Blueprint $table): void {
            $table->boolean('quiet_recording')->default(false)->after('is_featured');
        });
    }

    public function down(): void
    {
        Schema::table('song_videos', function (Blueprint $table): void {
            $table->dropColumn('quiet_recording');
        });
    }
};
