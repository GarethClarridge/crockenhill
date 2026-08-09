<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historic_import_source_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('historic_import_operation_id');
            $table->foreignId('historic_import_checkpoint_id')->nullable();
            $table->foreignId('historic_import_artifact_id');
            $table->string('source_kind', 40);
            $table->string('item_key');
            $table->string('file_key');
            $table->string('relative_path', 1024);
            $table->char('approved_sha256', 64);
            $table->char('observed_sha256', 64);
            $table->unsignedBigInteger('byte_size');
            $table->json('file_identity');
            $table->timestamp('captured_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['historic_import_operation_id', 'source_kind', 'item_key', 'file_key'],
                'historic_snapshot_operation_item_file_unique',
            );
            $table->unique('historic_import_artifact_id', 'historic_snapshot_artifact_unique');
            $table->foreign('historic_import_operation_id', 'historic_snapshot_operation_foreign')
                ->references('id')->on('historic_import_operations')->restrictOnDelete();
            $table->foreign('historic_import_checkpoint_id', 'historic_snapshot_checkpoint_foreign')
                ->references('id')->on('historic_import_checkpoints')->restrictOnDelete();
            $table->foreign('historic_import_artifact_id', 'historic_snapshot_artifact_foreign')
                ->references('id')->on('historic_import_artifacts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historic_import_source_snapshots');
    }
};
