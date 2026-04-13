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

        foreach (DB::table('pages')->select('area')->distinct()->pluck('area') as $area) {
            $normalized = strtolower(trim((string) $area));
            if (! in_array($normalized, $validAreas, true)) {
                $normalized = PageArea::Church->value;
            }

            DB::table('pages')->where('area', $area)->update(['area' => $normalized]);
        }

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
            DB::statement(sprintf('ALTER TABLE pages DROP CHECK %s', self::SORT_ORDER_CHECK));
        }

        Schema::table('pages', function (Blueprint $table) {
            $table->string('area', 50)->nullable(false)->change();

            if (Schema::hasColumn('pages', 'sort_order')) {
                $table->dropColumn('sort_order');
            }
        });
    }
};
