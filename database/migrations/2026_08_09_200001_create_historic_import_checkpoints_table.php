<?php

declare(strict_types=1);

use App\Enums\HistoricImportCheckpointState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historic_import_checkpoints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('historic_import_operation_id')->constrained()->restrictOnDelete();
            $table->string('checkpoint_key');
            $table->unsignedInteger('ordinal');
            $table->char('membership_hash', 64);
            $table->json('item_keys');
            $table->unsignedInteger('forecast_seconds');
            $table->string('state', 32)->default(HistoricImportCheckpointState::Planned->value);
            $table->timestamp('admitted_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->unique(['historic_import_operation_id', 'checkpoint_key'], 'historic_checkpoint_operation_key_unique');
            $table->unique(['historic_import_operation_id', 'ordinal'], 'historic_checkpoint_operation_ordinal_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historic_import_checkpoints');
    }
};
