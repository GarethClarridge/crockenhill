<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('media_processing_logs', function (Blueprint $table): void {
            $table->unsignedBigInteger('church_service_id')->nullable()->after('owner_user_id');
            $table->foreign('church_service_id')
                ->references('id')->on('church_services')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media_processing_logs', function (Blueprint $table): void {
            $table->dropForeign(['church_service_id']);
            $table->dropColumn('church_service_id');
        });
    }
};
