<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\ChurchServiceItemSource;
use App\Models\ChurchServiceItem;
use Illuminate\Database\QueryException;
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
            'source' => ChurchServiceItemSource::OpenLp->value,
        ]);

        Schema::table('church_service_items', function (Blueprint $table): void {
            $table->dropColumn('source');
        });

        $migration = require database_path('migrations/2026_03_08_190000_add_source_to_church_service_items_table.php');
        $migration->up();

        $source = DB::table('church_service_items')
            ->where('id', $item->id)
            ->value('source');

        $this->assertSame(ChurchServiceItemSource::OpenLp->value, $source);
    }

    #[Test]
    public function it_accepts_livestream_as_a_valid_source_value(): void
    {
        $item = ChurchServiceItem::factory()->livestream()->create();

        $source = DB::table('church_service_items')
            ->where('id', $item->id)
            ->value('source');

        $this->assertSame(ChurchServiceItemSource::Livestream->value, $source);
    }

    #[Test]
    public function it_enforces_unique_active_positions_per_service(): void
    {
        $item = ChurchServiceItem::factory()->create([
            'position' => 1,
        ]);

        $this->expectException(QueryException::class);

        DB::table('church_service_items')->insert([
            'church_service_id' => $item->church_service_id,
            'position' => 1,
            'type' => 'custom',
            'section_type' => null,
            'source' => ChurchServiceItemSource::OpenLp->value,
            'title' => 'Duplicate position',
            'source_title' => null,
            'openlp_search_title' => null,
            'song_id' => null,
            'metadata' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function a_soft_deleted_item_does_not_block_reusing_the_same_position(): void
    {
        $item = ChurchServiceItem::factory()->create([
            'position' => 1,
        ]);

        $item->delete();

        $replacement = ChurchServiceItem::factory()->create([
            'church_service_id' => $item->church_service_id,
            'position' => 1,
        ]);

        $this->assertSame(1, $replacement->position);
        $this->assertTrue($item->trashed());
    }

    #[Test]
    public function the_migration_repairs_duplicate_or_gapped_active_positions_before_adding_the_unique_shape(): void
    {
        $first = ChurchServiceItem::factory()->create(['position' => 1]);
        $second = ChurchServiceItem::factory()->create([
            'church_service_id' => $first->church_service_id,
            'position' => 2,
        ]);
        $third = ChurchServiceItem::factory()->create([
            'church_service_id' => $first->church_service_id,
            'position' => 3,
        ]);

        DB::statement('DROP INDEX church_service_items_active_position_unique ON church_service_items');
        Schema::table('church_service_items', function (Blueprint $table): void {
            $table->dropColumn('active_position');
        });

        DB::table('church_service_items')->where('id', $second->id)->update(['position' => 1]);
        DB::table('church_service_items')->where('id', $third->id)->update(['position' => 7]);

        $migration = require database_path('migrations/2026_03_22_120000_formalize_review_publication_state_and_ordering_invariants.php');
        $migration->up();

        $positions = ChurchServiceItem::query()
            ->where('church_service_id', $first->church_service_id)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->pluck('position')
            ->all();

        $this->assertSame([1, 2, 3], $positions);
        $this->assertTrue(Schema::hasColumn('church_service_items', 'active_position'));
        $this->assertTrue(Schema::hasIndex('church_service_items', 'church_service_items_active_position_unique'));
    }
}
