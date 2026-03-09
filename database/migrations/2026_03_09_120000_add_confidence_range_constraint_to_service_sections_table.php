<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $constraintName = 'service_sections_confidence_range_check';

    public function up(): void
    {
        if (! Schema::hasTable('service_sections') || ! Schema::hasColumn('service_sections', 'confidence')) {
            return;
        }

        if (! in_array($this->driverName(), ['mysql', 'pgsql'], true)) {
            return;
        }

        if ($this->constraintExists()) {
            return;
        }

        DB::statement(
            "ALTER TABLE service_sections ADD CONSTRAINT {$this->constraintName} ".
            'CHECK (confidence IS NULL OR (confidence >= 0.000 AND confidence <= 1.000))'
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('service_sections') || ! $this->constraintExists()) {
            return;
        }

        if ($this->driverName() === 'mysql') {
            DB::statement("ALTER TABLE service_sections DROP CHECK {$this->constraintName}");

            return;
        }

        DB::statement("ALTER TABLE service_sections DROP CONSTRAINT {$this->constraintName}");
    }

    private function constraintExists(): bool
    {
        if (! in_array($this->driverName(), ['mysql', 'pgsql'], true)) {
            return false;
        }

        $database = (string) DB::getDatabaseName();

        $constraint = DB::table('information_schema.table_constraints')
            ->select('constraint_name')
            ->where('table_schema', $database)
            ->where('table_name', 'service_sections')
            ->where('constraint_name', $this->constraintName)
            ->first();

        return $constraint !== null;
    }

    private function driverName(): string
    {
        return (string) DB::getDriverName();
    }
};
