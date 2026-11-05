<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * api.bible's FUMS token is the provider's usage receipt, sent back as written. The 11-02 round's
 * refresh discarded 33 valid passages because their tokens ran past 255 characters, so the column
 * holds any length; a token is never truncated to fit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scripture_passages', function (Blueprint $table): void {
            $table->text('fums_token')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('scripture_passages', function (Blueprint $table): void {
            $table->string('fums_token')->nullable()->change();
        });
    }
};
