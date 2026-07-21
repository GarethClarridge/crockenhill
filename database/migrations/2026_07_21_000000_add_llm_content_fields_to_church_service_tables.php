<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('church_services', function (Blueprint $table): void {
            $table->text('summary')->nullable()->after('review_reason');
            $table->json('notices')->nullable()->after('summary');
            $table->json('chapter_markers')->nullable()->after('notices');
        });

        Schema::table('service_sections', function (Blueprint $table): void {
            $table->text('summary')->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('church_services', function (Blueprint $table): void {
            $table->dropColumn(['summary', 'notices', 'chapter_markers']);
        });

        Schema::table('service_sections', function (Blueprint $table): void {
            $table->dropColumn('summary');
        });
    }
};
