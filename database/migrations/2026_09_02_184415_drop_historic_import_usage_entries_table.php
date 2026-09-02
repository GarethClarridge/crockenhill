<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1-4: delete the historic-import cost/usage reporting surface rather than repair it.
 *
 * `HistoricImportCostLedger` had no production pipeline call sites (Phase 4,
 * 2026-08-30) and the table it wrote to was empty throughout the 2026-09-02
 * pass that motivated this deletion — dropping it loses no data. Ordinary
 * provider usage telemetry survives via `OpenAiUsageLogger`'s application log
 * lines; only this inert internal ledger goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('historic_import_usage_entries');
    }

    public function down(): void
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
};
