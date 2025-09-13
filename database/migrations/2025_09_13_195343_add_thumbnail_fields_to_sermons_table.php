<?php

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
            $table->string('thumbnail_path')->nullable()->after('transcript_path');
            $table->timestamp('thumbnail_generated_at')->nullable()->after('thumbnail_path');
            $table->json('thumbnail_metadata')->nullable()->after('thumbnail_generated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sermons', function (Blueprint $table) {
            $table->dropColumn([
                'thumbnail_path',
                'thumbnail_generated_at',
                'thumbnail_metadata',
            ]);
        });
    }
};
