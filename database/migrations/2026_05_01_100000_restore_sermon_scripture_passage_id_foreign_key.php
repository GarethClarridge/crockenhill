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
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // 1. Clean up orphaned scripture_passage_id values that would block the FK
        DB::table('sermons')
            ->whereNotNull('scripture_passage_id')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('scripture_passages')
                    ->whereColumn('scripture_passages.id', 'sermons.scripture_passage_id');
            })
            ->update(['scripture_passage_id' => null]);

        // 2. Restore the foreign key dropped by a previous migration.
        // We use a manual statement to ensure consistent naming and parameters.
        if (! $this->foreignKeyExists()) {
            DB::statement(sprintf(
                'ALTER TABLE sermons ADD CONSTRAINT %s FOREIGN KEY (scripture_passage_id) REFERENCES scripture_passages (id) ON DELETE SET NULL',
                self::CONSTRAINT
            ));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if ($this->foreignKeyExists()) {
            DB::statement(sprintf('ALTER TABLE sermons DROP FOREIGN KEY %s', self::CONSTRAINT));
        }
    }

    private function foreignKeyExists(): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'sermons')
            ->where('constraint_name', self::CONSTRAINT)
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();
    }
};
