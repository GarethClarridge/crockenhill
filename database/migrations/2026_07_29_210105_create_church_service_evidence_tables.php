<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('church_service_source_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('church_service_id')->constrained()->cascadeOnDelete();
            $table->string('source', 20);
            $table->string('source_key');
            $table->char('revision_hash', 64);
            $table->char('input_hash', 64);
            $table->foreignId('supersedes_id')->nullable()->constrained('church_service_source_records')->nullOnDelete();
            $table->char('batch_hash', 64)->nullable();
            $table->json('processing_fingerprint');
            $table->json('service_content')->nullable();
            $table->boolean('payload_complete')->default(true);
            $table->timestamp('captured_at');
            $table->unsignedInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['source', 'source_key', 'revision_hash'], 'church_service_source_records_revision_unique');
            $table->index(['church_service_id', 'source', 'captured_at'], 'church_service_source_records_service_source_index');
            $table->index('batch_hash');
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('church_service_item_assertions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_record_id')->constrained('church_service_source_records')->cascadeOnDelete();
            $table->string('assertion_key');
            $table->unsignedInteger('source_position');
            $table->string('evidence_kind', 20);
            $table->string('type', 50);
            $table->string('section_type', 50)->nullable();
            $table->string('title');
            $table->string('source_title')->nullable();
            $table->string('normalized_title')->nullable();
            $table->unsignedInteger('song_id')->nullable();
            $table->string('song_canonical_key')->nullable();
            $table->string('scripture_reference')->nullable();
            $table->string('normalized_scripture_key')->nullable();
            $table->decimal('start_seconds', 10, 3)->nullable();
            $table->decimal('end_seconds', 10, 3)->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['source_record_id', 'assertion_key'], 'church_service_item_assertions_record_key_unique');
            $table->index(['source_record_id', 'source_position'], 'service_assertions_record_position_index');
            $table->index('song_canonical_key');
            $table->index('normalized_scripture_key');
            $table->foreign('song_id')->references('id')->on('songs')->nullOnDelete();
        });

        Schema::create('church_service_merge_proposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('church_service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trigger_source_record_id')->constrained('church_service_source_records')->cascadeOnDelete();
            $table->unsignedInteger('base_canonical_revision');
            $table->char('base_canonical_hash', 64)->nullable();
            $table->json('included_source_hashes');
            $table->json('proposed_items');
            $table->char('proposed_hash', 64);
            $table->json('field_decisions');
            $table->json('conflicts');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('resolved_by_user_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['church_service_id', 'status']);
            $table->index('proposed_hash');
            $table->foreign('resolved_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('church_service_review_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('review_uuid')->unique();
            $table->foreignId('church_service_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('base_canonical_revision');
            $table->char('base_canonical_hash', 64)->nullable();
            $table->json('included_proposal_ids');
            $table->json('service_field_decisions');
            $table->foreignId('manual_source_record_id')->nullable()->constrained('church_service_source_records')->nullOnDelete();
            $table->unsignedInteger('resulting_canonical_revision')->nullable();
            $table->char('resulting_canonical_hash', 64)->nullable();
            $table->unsignedInteger('reviewed_by_user_id');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['church_service_id', 'created_at'], 'service_review_sessions_created_index');
            $table->foreign('reviewed_by_user_id')->references('id')->on('users');
        });

        Schema::create('church_service_review_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('review_session_id')->constrained('church_service_review_sessions')->cascadeOnDelete();
            $table->foreignId('selected_assertion_id')->nullable()->constrained('church_service_item_assertions')->nullOnDelete();
            $table->boolean('included');
            $table->unsignedInteger('final_position')->nullable();
            $table->json('custom_value')->nullable();
            $table->unsignedInteger('song_id')->nullable();
            $table->string('song_canonical_key')->nullable();
            $table->string('scripture_reference')->nullable();
            $table->string('occurrence_decision', 30)->nullable();
            $table->text('rationale')->nullable();
            $table->timestamps();

            $table->index(['review_session_id', 'final_position'], 'service_review_decisions_position_index');
            $table->foreign('song_id')->references('id')->on('songs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('church_service_review_decisions');
        Schema::dropIfExists('church_service_review_sessions');
        Schema::dropIfExists('church_service_merge_proposals');
        Schema::dropIfExists('church_service_item_assertions');
        Schema::dropIfExists('church_service_source_records');
    }
};
