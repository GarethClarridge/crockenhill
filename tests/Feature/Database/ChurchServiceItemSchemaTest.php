<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\ChurchServiceItemSource;
use App\Models\ChurchServiceItem;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceItemSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_the_source_column_for_church_service_items(): void
    {
        $this->assertTrue(Schema::hasColumn('church_service_items', 'source'));
    }

    #[Test]
    public function add_source_migration_backfills_existing_items_as_openlp(): void
    {
        $item = ChurchServiceItem::factory()->create([
            'source' => ChurchServiceItemSource::OPENLP->value,
        ]);

        Schema::table('church_service_items', function (Blueprint $table): void {
            $table->dropColumn('source');
        });

        $migration = require database_path('migrations/2026_03_08_190000_add_source_to_church_service_items_table.php');
        $migration->up();

        $source = DB::table('church_service_items')
            ->where('id', $item->id)
            ->value('source');

        $this->assertSame(ChurchServiceItemSource::OPENLP->value, $source);
    }
}
