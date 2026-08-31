<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_sections', function (Blueprint $table): void {
            $table->string('asset_disk')->nullable()->after('extracted_audio_path');
        });
    }

    public function down(): void
    {
        Schema::table('service_sections', function (Blueprint $table): void {
            $table->dropColumn('asset_disk');
        });
    }
};
