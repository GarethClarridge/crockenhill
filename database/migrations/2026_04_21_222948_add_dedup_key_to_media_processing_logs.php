<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_processing_logs', function (Blueprint $table): void {
            $table->string('dedup_key', 128)->nullable()->after('file_hash');
            $table->unique('dedup_key');
        });
    }

    public function down(): void
    {
        Schema::table('media_processing_logs', function (Blueprint $table): void {
            $table->dropUnique(['dedup_key']);
            $table->dropColumn('dedup_key');
        });
    }
};
