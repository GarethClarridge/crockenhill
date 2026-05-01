<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONSTRAINT = 'sermons_scripture_passage_id_foreign';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('sermons')) {
            return;
        }

        // 1. Clean up orphaned scripture_passage_id values that would block the FK.
        // Orphans could have been introduced while the constraint was missing.
        DB::table('sermons')
            ->whereNotNull('scripture_passage_id')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('scripture_passages')
                    ->whereColumn('scripture_passages.id', 'sermons.scripture_passage_id');
            })
            ->update(['scripture_passage_id' => null]);

        // 2. Restore the foreign key using the Schema builder for better consistency.
        if (! $this->foreignKeyExists()) {
            Schema::table('sermons', function (Blueprint $table) {
                $table->foreign('scripture_passage_id', self::CONSTRAINT)
                    ->references('id')
                    ->on('scripture_passages')
                    ->onDelete('set null');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('sermons')) {
            return;
        }

        if ($this->foreignKeyExists()) {
            Schema::table('sermons', function (Blueprint $table) {
                $table->dropForeign(self::CONSTRAINT);
            });
        }
    }

    /**
     * Check if the foreign key exists in the database.
     * Uses DATABASE() to ensure the check is scoped to the current schema.
     */
    private function foreignKeyExists(): bool
    {
        return collect(DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'sermons'
            AND CONSTRAINT_NAME = ?
            AND CONSTRAINT_TYPE = 'FOREIGN KEY'
        ", [self::CONSTRAINT]))->isNotEmpty();
    }
};
