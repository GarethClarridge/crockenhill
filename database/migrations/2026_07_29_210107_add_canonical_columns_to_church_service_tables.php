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
            $table->unsignedInteger('canonical_revision')->default(0);
            $table->char('canonical_hash', 64)->nullable();
            $table->unsignedInteger('reviewed_canonical_revision')->nullable();
            $table->string('source_summary', 20)->nullable();
        });

        Schema::table('church_service_items', function (Blueprint $table): void {
            $table->string('canonical_identity')->nullable()->index();
            $table->string('occurrence_state', 30)->nullable();
            $table->boolean('manual_occurrence_decision')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('church_service_items', function (Blueprint $table): void {
            $table->dropIndex(['canonical_identity']);
            $table->dropColumn(['canonical_identity', 'occurrence_state', 'manual_occurrence_decision']);
        });

        Schema::table('church_services', function (Blueprint $table): void {
            $table->dropColumn(['canonical_revision', 'canonical_hash', 'reviewed_canonical_revision', 'source_summary']);
        });
    }
};
