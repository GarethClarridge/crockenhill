<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HIR7's release ledger.
 *
 * Release is a second writer to storage, and unlike the apply step it writes to
 * the **final public path** rather than an operation-owned key. Two attempts
 * could therefore both observe that path as absent, both write it, and the loser
 * could delete the winner's published bytes while compensating. Nothing durable
 * recorded who owned a destination, so nothing could refuse the second owner.
 *
 * These tables are that record. The uniqueness that matters is on
 * `destination_identity` and is **global** rather than operation-scoped: one
 * owner per public destination, ever, whichever operation or batch claims it.
 *
 * New tables rather than columns on the import-transfer ledger, which describes
 * a different writer with different compensation rules.
 *
 * Delete after the accepted public release/rollback observation window and the
 * artifact-retention decision (G9/WP10), using a later contract release.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historic_import_release_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('attempt_id')->unique();
            /** Named explicitly: the generated identifier exceeds MySQL's 64-character limit. */
            $table->foreignId('historic_import_operation_id')
                ->constrained(indexName: 'historic_release_attempt_operation_foreign')
                ->restrictOnDelete();
            $table->string('authorisation_id')->nullable();
            $table->string('batch_key')->nullable();
            /** Binds the attempt to the exact signed authority and membership it was claimed for. */
            $table->char('authorisation_hash', 64);
            $table->char('membership_hash', 64);
            $table->string('state', 24)->default('claimed');
            $table->uuid('lease_token');
            $table->timestamp('lease_expires_at');
            $table->string('failure_summary', 500)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            /**
             * Exact uniqueness for the signed batch identity: re-running the same
             * authorisation over the same membership resolves the attempt that
             * already exists rather than creating a second owner.
             */
            $table->unique(
                ['historic_import_operation_id', 'authorisation_hash', 'membership_hash'],
                'historic_release_attempt_batch_unique',
            );
            $table->index(['state', 'lease_expires_at'], 'historic_release_attempt_lease_index');
        });

        Schema::create('historic_import_release_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('historic_import_release_attempt_id')
                ->constrained('historic_import_release_attempts', indexName: 'historic_release_asset_attempt_foreign')
                ->cascadeOnDelete();
            $table->string('record_type', 24);
            $table->unsignedBigInteger('record_id');
            $table->string('source_disk');
            $table->text('source_path');
            $table->string('destination_disk');
            $table->text('destination_path');
            /**
             * sha256(disk|path). A destination path can be longer than an InnoDB
             * index key allows, and a truncated prefix index would let two
             * different long paths collide into one claim.
             */
            $table->char('destination_identity', 64);
            $table->unsignedBigInteger('size')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('state', 24)->default('claimed');
            /** `created` by this attempt, or `preexisting` and never cleanup-owned. */
            $table->string('create_result', 24)->nullable();
            $table->string('provider_receipt', 255)->nullable();
            $table->string('provider_version_id', 255)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('compensated_at')->nullable();
            $table->timestamps();

            /** Global, not per operation: the whole point of the ledger. */
            $table->unique('destination_identity', 'historic_release_destination_unique');
            $table->index(
                ['historic_import_release_attempt_id', 'state'],
                'historic_release_asset_attempt_state_index',
            );
            $table->index(['record_type', 'record_id'], 'historic_release_asset_record_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historic_import_release_assets');
        Schema::dropIfExists('historic_import_release_attempts');
    }
};
