<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historic_import_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('historic_import_operation_id');
            $table->foreignId('media_processing_log_id')->nullable();
            $table->string('alert_key');
            $table->string('kind', 40);
            $table->string('severity', 20);
            $table->json('payload');
            $table->timestamp('recorded_at');

            $table->unique(
                ['historic_import_operation_id', 'alert_key'],
                'historic_alert_operation_key_unique',
            );
            $table->foreign('historic_import_operation_id', 'historic_alert_operation_foreign')
                ->references('id')->on('historic_import_operations')->restrictOnDelete();
            $table->foreign('media_processing_log_id', 'historic_alert_processing_log_foreign')
                ->references('id')->on('media_processing_logs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historic_import_alerts');
    }
};
