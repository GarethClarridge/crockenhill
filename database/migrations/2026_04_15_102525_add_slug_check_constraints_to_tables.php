<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Case-sensitive regex pattern for strict kebab-case slugs.
     * Pattern: lowercase alphanumeric only, hyphens allowed in middle.
     */
    private const SLUG_CHECK_PATTERN = '^[a-z0-9]+(?:-[a-z0-9]+)*$';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $tables = ['sermons', 'preachers', 'meetings', 'pages'];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                $constraintName = "{$table}_slug_format_check";

                // We use REGEXP_LIKE with 'c' match parameter for case-sensitive matching in MySQL 8+
                // Wrapped in try-catch: existing data may violate the constraint (handled by subsequent migration).
                try {
                    DB::statement(sprintf(
                        "ALTER TABLE %s ADD CONSTRAINT %s CHECK (REGEXP_LIKE(slug, '%s', 'c'))",
                        $table,
                        $constraintName,
                        self::SLUG_CHECK_PATTERN
                    ));
                } catch (\Illuminate\Database\QueryException) {
                    // Data already in the table violates the constraint; skip silently.
                }
            }
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

        $tables = ['sermons', 'preachers', 'meetings', 'pages'];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                $constraintName = "{$table}_slug_format_check";
                DB::statement(sprintf(
                    'ALTER TABLE %s DROP CHECK %s',
                    $table,
                    $constraintName
                ));
            }
        }
    }
};
