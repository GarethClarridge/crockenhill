<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('songs') || ! Schema::hasColumn('songs', 'copyright')) {
            return;
        }

        Schema::table('songs', function (Blueprint $table): void {
            $table->text('copyright')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('songs') || ! Schema::hasColumn('songs', 'copyright')) {
            return;
        }

        Schema::table('songs', function (Blueprint $table): void {
            $table->string('copyright')->nullable()->change();
        });
    }
};
