<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historic_import_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('historic_import_operation_id')->constrained()->restrictOnDelete();
            $table->foreignId('historic_import_checkpoint_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('artifact_key');
            $table->string('kind', 40);
            $table->string('storage_disk');
            $table->string('relative_path', 1024);
            $table->char('sha256', 64);
            $table->unsignedBigInteger('byte_size');
            $table->boolean('encrypted');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['historic_import_operation_id', 'artifact_key'], 'historic_artifact_operation_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historic_import_artifacts');
    }
};
