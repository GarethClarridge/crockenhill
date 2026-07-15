<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminSortIndexesTest extends TestCase
{
    #[Test]
    public function it_has_indexes_on_updated_at_for_admin_tables(): void
    {
        $this->assertIndexExists('sermons', 'sermons_updated_at_index');
        $this->assertIndexExists('pages', 'pages_updated_at_index');
        $this->assertIndexExists('meetings', 'meetings_updated_at_index');
        $this->assertIndexExists('preachers', 'preachers_updated_at_index');
    }

    #[Test]
    public function it_has_indexes_on_created_at_for_relevant_admin_tables(): void
    {
        $this->assertIndexExists('sermons', 'sermons_created_at_index');
        $this->assertIndexExists('meetings', 'meetings_created_at_index');
        $this->assertIndexExists('preachers', 'preachers_created_at_index');
    }

    private function assertIndexExists(string $table, string $index): void
    {
        $indexes = DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$index]);

        $this->assertNotEmpty($indexes, "Index '{$index}' does not exist on table '{$table}'.");
    }
}
