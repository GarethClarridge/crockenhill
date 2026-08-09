<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historic_import_item_outcomes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('historic_import_operation_id');
            $table->foreignId('historic_import_checkpoint_id')->nullable();
            $table->foreignId('historic_import_source_snapshot_id')->nullable();
            $table->string('source_kind', 40);
            $table->string('item_key');
            $table->string('expectation', 20);
            $table->string('disposition', 40);
            $table->char('approved_source_sha256', 64)->nullable();
            $table->char('observed_source_sha256', 64)->nullable();
            $table->json('output_hashes');
            $table->string('reason_code')->nullable();
            $table->timestamp('settled_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['historic_import_operation_id', 'source_kind', 'item_key'],
                'historic_outcome_operation_item_unique',
            );
            $table->foreign('historic_import_operation_id', 'historic_outcome_operation_foreign')
                ->references('id')->on('historic_import_operations')->restrictOnDelete();
            $table->foreign('historic_import_checkpoint_id', 'historic_outcome_checkpoint_foreign')
                ->references('id')->on('historic_import_checkpoints')->restrictOnDelete();
            $table->foreign('historic_import_source_snapshot_id', 'historic_outcome_snapshot_foreign')
                ->references('id')->on('historic_import_source_snapshots')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historic_import_item_outcomes');
    }
};
