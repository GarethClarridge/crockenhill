<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historic_import_asset_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('historic_import_operation_id');
            $table->foreignId('historic_import_checkpoint_id')->nullable();
            $table->string('transfer_key');
            $table->string('source_disk');
            $table->string('source_path');
            $table->string('destination_disk');
            $table->string('destination_path');
            $table->unsignedBigInteger('byte_size');
            $table->char('sha256', 64);
            $table->string('state', 24);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('retain_until')->nullable();
            $table->timestamps();

            $table->unique(['historic_import_operation_id', 'transfer_key'], 'historic_transfer_operation_key_unique');
            $table->unique(
                ['historic_import_operation_id', 'destination_disk', 'destination_path'],
                'historic_transfer_operation_destination_unique',
            );
            $table->foreign('historic_import_operation_id', 'historic_transfer_operation_foreign')
                ->references('id')->on('historic_import_operations')->restrictOnDelete();
            $table->foreign('historic_import_checkpoint_id', 'historic_transfer_checkpoint_foreign')
                ->references('id')->on('historic_import_checkpoints')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historic_import_asset_transfers');
    }
};
