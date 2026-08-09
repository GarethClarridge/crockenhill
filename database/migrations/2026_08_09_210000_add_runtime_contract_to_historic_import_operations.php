<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('historic_import_operations', function (Blueprint $table): void {
            $table->string('notification_mode', 32)->default('external_disabled')->after('target_fingerprint');
            $table->unsignedBigInteger('max_cost_minor_units')->default(0)->after('notification_mode');
        });

        Schema::table('historic_import_checkpoints', function (Blueprint $table): void {
            $table->char('runtime_fingerprint', 64)->nullable()->after('forecast_seconds');
            $table->unsignedBigInteger('accepted_cost_minor_units')->default(0)->after('runtime_fingerprint');
            $table->timestamp('deadline_at')->nullable()->after('accepted_cost_minor_units');
            $table->timestamp('last_reconciled_at')->nullable()->after('settled_at');
        });

        Schema::table('media_processing_logs', function (Blueprint $table): void {
            $table->foreignId('historic_import_operation_id')->nullable()->after('processing_id');
            $table->foreign('historic_import_operation_id', 'media_processing_historic_operation_foreign')
                ->references('id')->on('historic_import_operations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('media_processing_logs', function (Blueprint $table): void {
            $table->dropForeign('media_processing_historic_operation_foreign');
            $table->dropColumn('historic_import_operation_id');
        });

        Schema::table('historic_import_checkpoints', function (Blueprint $table): void {
            $table->dropColumn([
                'runtime_fingerprint',
                'accepted_cost_minor_units',
                'deadline_at',
                'last_reconciled_at',
            ]);
        });

        Schema::table('historic_import_operations', function (Blueprint $table): void {
            $table->dropColumn(['notification_mode', 'max_cost_minor_units']);
        });
    }
};
