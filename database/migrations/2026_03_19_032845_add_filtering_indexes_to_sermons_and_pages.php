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
        Schema::table('sermons', function (Blueprint $table) {
            $table->index('needs_preacher_review');
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->index('navigation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sermons', function (Blueprint $table) {
            $table->dropIndex(['needs_preacher_review']);
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->dropIndex(['navigation']);
        });
    }
};
