<?php

declare(strict_types=1);

use App\Enums\HistoricImportOperationState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historic_import_operations', function (Blueprint $table): void {
            $table->id();
            $table->string('operation_id', 41)->unique();
            $table->char('binding_hash', 64)->unique();
            $table->string('batch_key');
            $table->json('manifest_hashes');
            $table->char('plan_hash', 64);
            $table->char('target_fingerprint', 64);
            $table->string('state', 32)->default(HistoricImportOperationState::Planned->value);
            $table->timestamp('accepted_deadline')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historic_import_operations');
    }
};
