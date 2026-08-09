<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historic_import_usage_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('historic_import_operation_id');
            $table->foreignId('historic_import_checkpoint_id');
            $table->string('request_key');
            $table->string('item_key');
            $table->string('provider', 80);
            $table->string('model', 120);
            $table->unsignedInteger('calls')->default(1);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('audio_seconds')->default(0);
            $table->unsignedBigInteger('cost_minor_units');
            $table->char('currency', 3);
            $table->timestamp('recorded_at');

            $table->unique(
                ['historic_import_operation_id', 'request_key'],
                'historic_usage_operation_request_unique',
            );
            $table->index(
                ['historic_import_checkpoint_id', 'item_key'],
                'historic_usage_checkpoint_item_index',
            );
            $table->foreign('historic_import_operation_id', 'historic_usage_operation_foreign')
                ->references('id')->on('historic_import_operations')->restrictOnDelete();
            $table->foreign('historic_import_checkpoint_id', 'historic_usage_checkpoint_foreign')
                ->references('id')->on('historic_import_checkpoints')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historic_import_usage_entries');
    }
};
