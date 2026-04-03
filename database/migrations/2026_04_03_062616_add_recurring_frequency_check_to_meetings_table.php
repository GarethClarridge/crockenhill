<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CONSTRAINT_NAME = 'meetings_recurring_frequency_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf(
                'ALTER TABLE meetings ADD CONSTRAINT %s CHECK (is_recurring = 0 OR frequency IS NOT NULL)',
                self::CONSTRAINT_NAME
            ));
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                'ALTER TABLE meetings ADD CONSTRAINT %s CHECK (is_recurring = false OR frequency IS NOT NULL)',
                self::CONSTRAINT_NAME
            ));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf('ALTER TABLE meetings DROP CHECK %s', self::CONSTRAINT_NAME));
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement(sprintf('ALTER TABLE meetings DROP CONSTRAINT %s', self::CONSTRAINT_NAME));
        }
    }
};
