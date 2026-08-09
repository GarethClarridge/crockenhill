<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historic_import_journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('historic_import_operation_id');
            $table->foreignId('historic_import_checkpoint_id')->nullable();
            $table->unsignedBigInteger('sequence');
            $table->string('event', 80);
            $table->string('disposition', 40)->nullable();
            $table->json('payload');
            $table->char('previous_entry_hash', 64)->nullable();
            $table->char('entry_hash', 64)->unique();
            $table->timestamp('recorded_at');

            $table->unique(['historic_import_operation_id', 'sequence'], 'historic_journal_operation_sequence_unique');
            $table->foreign('historic_import_operation_id', 'historic_journal_operation_foreign')
                ->references('id')->on('historic_import_operations')->restrictOnDelete();
            $table->foreign('historic_import_checkpoint_id', 'historic_journal_checkpoint_foreign')
                ->references('id')->on('historic_import_checkpoints')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historic_import_journal_entries');
    }
};
