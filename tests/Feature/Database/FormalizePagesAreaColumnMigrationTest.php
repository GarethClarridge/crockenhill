<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\PageArea;
use App\Models\Page;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FormalizePagesAreaColumnMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const ZERO_TIMESTAMP = '0000-00-00 00:00:00';

    private const SORT_ORDER_CHECK = 'pages_sort_order_check';

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require base_path('database/migrations/2026_04_13_082111_formalize_pages_area_column.php');

        return $migration;
    }

    #[Test]
    public function it_cleans_legacy_zero_timestamps_before_formalizing_the_area_column(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('This migration regression test requires MySQL.');
        }

        $page = Page::factory()->create([
            'area' => PageArea::Church->value,
        ]);

        $this->dropPagesSortOrderCheckIfPresent();
        DB::statement('ALTER TABLE pages MODIFY area VARCHAR(50) NOT NULL');
        $this->setPageTimestampsToZero($page->id);

        $this->migration()->up();

        $row = DB::table('pages')
            ->selectRaw('CAST(created_at AS CHAR(19)) AS created_at_raw, CAST(updated_at AS CHAR(19)) AS updated_at_raw')
            ->where('id', $page->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertNotSame(self::ZERO_TIMESTAMP, $row->created_at_raw);
        $this->assertNotSame(self::ZERO_TIMESTAMP, $row->updated_at_raw);
        $this->assertStringStartsWith('enum(', $this->columnType('pages', 'area'));
    }

    private function setPageTimestampsToZero(int $pageId): void
    {
        $originalSqlMode = (string) (DB::selectOne('SELECT @@SESSION.sql_mode AS sql_mode')->sql_mode ?? '');

        try {
            DB::statement(
                "SET SESSION sql_mode = REPLACE(REPLACE(REPLACE(REPLACE(@@SESSION.sql_mode, 'NO_ZERO_DATE,', ''), ',NO_ZERO_DATE', ''), 'NO_ZERO_IN_DATE,', ''), ',NO_ZERO_IN_DATE', '')"
            );

            DB::statement(sprintf(
                "UPDATE pages SET created_at = '%s', updated_at = '%s' WHERE id = %d",
                self::ZERO_TIMESTAMP,
                self::ZERO_TIMESTAMP,
                $pageId
            ));
        } finally {
            DB::statement(sprintf(
                "SET SESSION sql_mode = '%s'",
                str_replace("'", "''", $originalSqlMode)
            ));
        }
    }

    private function dropPagesSortOrderCheckIfPresent(): void
    {
        try {
            DB::statement(sprintf('ALTER TABLE pages DROP CHECK %s', self::SORT_ORDER_CHECK));
        } catch (\Throwable) {
            // The constraint may already be absent in older schemas.
        }
    }

    private function columnType(string $table, string $column): string
    {
        return (string) DB::table('information_schema.columns')
            ->whereRaw('table_schema = database()')
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->value('column_type');
    }
}
