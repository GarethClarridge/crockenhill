<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Ensure no orphaned records exist before adding the constraint
        DB::table('sermons')
            ->whereNotNull('livestream_processing_id')
            ->whereNotIn('livestream_processing_id', function ($query) {
                $query->select('processing_id')->from('media_processing_logs');
            })
            ->update(['livestream_processing_id' => null]);

        if (DB::getDriverName() === 'mysql') {
            Schema::table('sermons', function (Blueprint $table) {
                $table->char('livestream_processing_id', 36)
                    ->nullable()
                    ->collation('utf8mb4_unicode_ci')
                    ->change();
            });
        }

        Schema::table('sermons', function (Blueprint $table) {
            $table->foreign('livestream_processing_id')
                ->references('processing_id')
                ->on('media_processing_logs')
                ->onUpdate('cascade')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sermons', function (Blueprint $table) {
            $table->dropForeign(['livestream_processing_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('sermons', function (Blueprint $table) {
                $table->string('livestream_processing_id', 36)
                    ->nullable()
                    ->collation('utf8mb4_unicode_ci')
                    ->change();
            });
        }
    }
};
