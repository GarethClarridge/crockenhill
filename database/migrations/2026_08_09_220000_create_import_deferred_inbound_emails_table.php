<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_deferred_inbound_emails', function (Blueprint $table): void {
            $table->id();
            $table->string('operation_id');
            $table->foreignId('inbound_email_id')->constrained()->restrictOnDelete();
            $table->string('state', 24)->default('pending');
            $table->unsignedInteger('dispatch_attempts')->default(0);
            $table->timestamp('deferred_at');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['operation_id', 'inbound_email_id'], 'import_deferred_operation_email_unique');
            $table->index(['operation_id', 'state', 'id'], 'import_deferred_operation_state_index');
            $table->foreign('operation_id', 'import_deferred_operation_foreign')
                ->references('operation_id')->on('import_ingress_locks')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_deferred_inbound_emails');
    }
};
