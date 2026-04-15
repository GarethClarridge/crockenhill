<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SLUG_CHECK_PATTERN = "^[a-z0-9]+(?:-[a-z0-9]+)*$";

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
                DB::statement(sprintf(
                    "ALTER TABLE %s ADD CONSTRAINT %s CHECK (slug REGEXP '%s')",
                    $table,
                    $constraintName,
                    self::SLUG_CHECK_PATTERN
                ));
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
                    "ALTER TABLE %s DROP CHECK %s",
                    $table,
                    $constraintName
                ));
            }
        }
    }
};
