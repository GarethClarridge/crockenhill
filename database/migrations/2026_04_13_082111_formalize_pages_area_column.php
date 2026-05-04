<?php

declare(strict_types=1);

use App\Enums\PageArea;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SORT_ORDER_CHECK = 'pages_sort_order_check';

    private const ZERO_TIMESTAMP = '0000-00-00 00:00:00';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('pages')) {
            return;
        }

        // 1. Add missing sort_order column if it doesn't exist
        Schema::table('pages', function (Blueprint $table) {
            if (! Schema::hasColumn('pages', 'sort_order')) {
                $table->unsignedInteger('sort_order')->nullable()->after('navigation');
            }
        });

        // 2. Data Cleanup: Normalize area values to lowercase and ensure they are valid
        $validAreas = PageArea::values();

        DB::table('pages')->update([
            'area' => DB::raw('LOWER(TRIM(area))'),
        ]);

        DB::table('pages')
            ->whereNotIn('area', $validAreas)
            ->update(['area' => PageArea::Church->value]);

        $this->normalizeLegacyZeroTimestamps();

        // 3. Formalize area as ENUM and add CHECK constraint for sort_order
        $isMysql = DB::getDriverName() === 'mysql';

        Schema::table('pages', function (Blueprint $table) use ($validAreas, $isMysql) {
            if ($isMysql) {
                $table->enum('area', $validAreas)->nullable(false)->change();
            } else {
                $table->string('area', 50)->nullable(false)->change();
            }
        });

        if ($isMysql) {
            DB::statement(sprintf(
                'ALTER TABLE pages ADD CONSTRAINT %s CHECK (sort_order >= 0 OR sort_order IS NULL)',
                self::SORT_ORDER_CHECK
            ));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            try {
                DB::statement(sprintf('ALTER TABLE pages DROP CHECK %s', self::SORT_ORDER_CHECK));
            } catch (Exception) {
                // Ignore if constraint doesn't exist
            }
        }

        Schema::table('pages', function (Blueprint $table) {
            $table->string('area', 50)->nullable(false)->change();

            /**
             * Safety: Avoid dropping sort_order on rollback.
             *
             * Since this migration is defensive about adding the column in up(),
             * we remain defensive in down(). We do not drop the column because
             * it might have existed before this migration was run, and dropping
             * it would be destructive to data this migration didn't own.
             */
        });
    }

    private function normalizeLegacyZeroTimestamps(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Older local databases can contain zero-date page timestamps, which MySQL strict
        // mode rejects when the area column change rebuilds the table.
        DB::statement(sprintf(
            "UPDATE pages SET created_at = CURRENT_TIMESTAMP WHERE CAST(created_at AS CHAR(19)) = '%s'",
            self::ZERO_TIMESTAMP
        ));

        DB::statement(sprintf(
            "UPDATE pages SET updated_at = CURRENT_TIMESTAMP WHERE CAST(updated_at AS CHAR(19)) = '%s'",
            self::ZERO_TIMESTAMP
        ));
    }
};
