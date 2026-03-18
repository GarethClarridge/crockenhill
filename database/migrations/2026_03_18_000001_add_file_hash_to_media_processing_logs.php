<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_processing_logs', function (Blueprint $table): void {
            $table->string('file_hash', 64)->nullable()->after('original_filename');
            $table->index('file_hash');
        });
    }

    public function down(): void
    {
        Schema::table('media_processing_logs', function (Blueprint $table): void {
            $table->dropIndex(['file_hash']);
            $table->dropColumn('file_hash');
        });
    }
};
