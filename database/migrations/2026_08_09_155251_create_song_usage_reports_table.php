<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('song_usage_reports', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('song_id')->nullable();
            $table->date('used_on');
            $table->string('reported_service', 20)->nullable();
            $table->foreignId('resolved_church_service_item_id')->nullable();
            $table->string('reported_title');
            $table->string('reported_number')->nullable();
            $table->string('catalog_title')->nullable();
            $table->string('match_method', 30)->nullable();
            $table->string('source_workbook');
            $table->string('source_sheet', 50);
            $table->unsignedInteger('source_row');
            $table->char('source_fingerprint', 64)->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['song_id', 'used_on'], 'song_usage_reports_song_date_index');
            $table->index(['used_on', 'reported_service'], 'song_usage_reports_date_service_index');
            $table->foreign('song_id')->references('id')->on('songs')->nullOnDelete();
            $table->foreign('resolved_church_service_item_id')->references('id')->on('church_service_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('song_usage_reports');
    }
};
