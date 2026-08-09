<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\ChurchServiceItem;
use App\Models\Song;
use App\Models\SongUsageReport;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongUsageReportSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_stores_date_only_song_usage_without_creating_a_service(): void
    {
        $song = Song::factory()->create();

        $report = SongUsageReport::factory()->create([
            'song_id' => $song->id,
            'used_on' => '2007-06-17',
            'reported_service' => null,
            'resolved_church_service_item_id' => null,
        ]);

        $this->assertSame('2007-06-17', $report->used_on->toDateString());
        $this->assertNull($report->reported_service);
        $this->assertTrue($report->song->is($song));
        $this->assertSame(0, DB::table('church_services')->count());
    }

    #[Test]
    public function it_enforces_source_identity_and_nulls_a_deleted_resolution(): void
    {
        $item = ChurchServiceItem::factory()->create();
        $report = SongUsageReport::factory()->create([
            'resolved_church_service_item_id' => $item->id,
            'source_fingerprint' => hash('sha256', 'historic-row'),
        ]);

        $item->forceDelete();

        $this->assertNull($report->refresh()->resolved_church_service_item_id);

        $this->expectException(QueryException::class);

        SongUsageReport::factory()->create([
            'source_fingerprint' => hash('sha256', 'historic-row'),
        ]);
    }

    #[Test]
    public function it_has_the_query_indexes_used_by_song_history(): void
    {
        $indexes = collect(Schema::getIndexes('song_usage_reports'))->pluck('name');

        $this->assertTrue($indexes->contains('song_usage_reports_song_date_index'));
        $this->assertTrue($indexes->contains('song_usage_reports_date_service_index'));
        $this->assertTrue($indexes->contains('song_usage_reports_source_fingerprint_unique'));
    }
}
