<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\ChurchServiceReviewState;
use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\Song;
use App\Services\LegacyPlayDateSongUsageImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class LegacyPlayDateSongUsageImporterTest extends TestCase
{
    use RefreshDatabase;

    private LegacyPlayDateSongUsageImporter $service;

    private string $tempSqlPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LegacyPlayDateSongUsageImporter;
        $this->tempSqlPath = (string) tempnam(sys_get_temp_dir(), 'legacy_import_test');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempSqlPath)) {
            unlink($this->tempSqlPath);
        }
        parent::tearDown();
    }

    #[Test]
    public function it_imports_valid_sql_dump_successfully(): void
    {
        $song1 = Song::factory()->create(['id' => 101, 'title' => 'Amazing Grace']);
        $song2 = Song::factory()->create(['id' => 102, 'title' => 'How Great Thou Art']);

        $sqlContent = "
INSERT INTO `play_date` VALUES (1,'101','2024-03-24','a','');
INSERT INTO `play_date` VALUES (2,'102','2024-03-24','p','');
";
        file_put_contents($this->tempSqlPath, $sqlContent);

        $metrics = $this->service->import($this->tempSqlPath);

        $this->assertEquals(2, $metrics['rows_parsed']);
        $this->assertEquals(2, $metrics['items_imported']);
        $this->assertEquals(2, $metrics['services_created']);

        $this->assertDatabaseHas('church_services', [
            'date' => '2024-03-24',
            'service' => SermonService::Morning->value,
            'review_state' => ChurchServiceReviewState::Reviewed->value,
        ]);

        $this->assertDatabaseHas('church_service_items', [
            'song_id' => $song1->id,
            'title' => 'Amazing Grace',
        ]);

        $this->assertDatabaseHas('church_service_items', [
            'song_id' => $song2->id,
            'title' => 'How Great Thou Art',
        ]);
    }

    #[Test]
    public function it_handles_dry_run_correctly(): void
    {
        Song::factory()->create(['id' => 101]);

        $sqlContent = "INSERT INTO `play_date` VALUES (1,'101','2024-03-24','a','');";
        file_put_contents($this->tempSqlPath, $sqlContent);

        $metrics = $this->service->import($this->tempSqlPath, dryRun: true);

        $this->assertTrue($metrics['dry_run']);
        $this->assertEquals(1, $metrics['items_imported']);

        // Check that the service and item were not persisted after roll back
        $this->assertDatabaseMissing('church_services', [
            'date' => '2024-03-24',
            'service' => SermonService::Morning->value,
        ]);
    }

    #[Test]
    public function it_throws_exception_for_missing_songs(): void
    {
        $sqlContent = "INSERT INTO `play_date` VALUES (1,'999','2024-03-24','a','');";
        file_put_contents($this->tempSqlPath, $sqlContent);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Legacy play_date rows reference songs that do not exist');

        $this->service->import($this->tempSqlPath);
    }

    #[Test]
    public function it_throws_exception_for_non_existent_file(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Legacy SQL dump path does not exist');

        $this->service->import('/non/existent/path.sql');
    }

    #[Test]
    public function it_throws_exception_for_empty_sql_dump(): void
    {
        file_put_contents($this->tempSqlPath, "-- Just a comment\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No legacy play_date rows were found');

        $this->service->import($this->tempSqlPath);
    }

    #[Test]
    public function it_reuses_existing_services(): void
    {
        Song::factory()->create(['id' => 101]);
        ChurchService::factory()->create([
            'date' => '2024-03-24',
            'service' => SermonService::Morning,
        ]);

        $sqlContent = "INSERT INTO `play_date` VALUES (1,'101','2024-03-24','a','');";
        file_put_contents($this->tempSqlPath, $sqlContent);

        $metrics = $this->service->import($this->tempSqlPath);

        $this->assertEquals(1, $metrics['services_reused']);
        $this->assertEquals(0, $metrics['services_created']);
    }

    #[Test]
    public function it_skips_already_imported_items_by_legacy_id(): void
    {
        $song = Song::factory()->create(['id' => 101]);
        $service = ChurchService::factory()->create([
            'date' => '2024-03-24',
            'service' => SermonService::Morning,
        ]);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'song_id' => $song->id,
            'metadata' => ['legacy_play_date_id' => 1],
        ]);

        $sqlContent = "INSERT INTO `play_date` VALUES (1,'101','2024-03-24','a','');";
        file_put_contents($this->tempSqlPath, $sqlContent);

        $metrics = $this->service->import($this->tempSqlPath);

        $this->assertEquals(1, $metrics['items_skipped_existing_row']);
        $this->assertEquals(0, $metrics['items_imported']);
    }

    #[Test]
    public function it_skips_already_imported_songs_in_same_service(): void
    {
        $song = Song::factory()->create(['id' => 101]);
        $service = ChurchService::factory()->create([
            'date' => '2024-03-24',
            'service' => SermonService::Morning,
        ]);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'song_id' => $song->id,
        ]);

        // Legacy ID is different, but song ID is already present in this service
        $sqlContent = "INSERT INTO `play_date` VALUES (2,'101','2024-03-24','a','');";
        file_put_contents($this->tempSqlPath, $sqlContent);

        $metrics = $this->service->import($this->tempSqlPath);

        $this->assertEquals(1, $metrics['items_skipped_existing_song']);
        $this->assertEquals(0, $metrics['items_imported']);
    }

    #[Test]
    public function it_correctly_groups_multiple_songs_per_service(): void
    {
        Song::factory()->create(['id' => 101]);
        Song::factory()->create(['id' => 102]);

        $sqlContent = "INSERT INTO `play_date` VALUES (1,'101','2024-03-24','a',''),(2,'102','2024-03-24','a','');";
        file_put_contents($this->tempSqlPath, $sqlContent);

        $metrics = $this->service->import($this->tempSqlPath);

        $this->assertEquals(2, $metrics['items_imported']);
        $this->assertEquals(1, $metrics['services_created']);

        $service = ChurchService::where('date', '2024-03-24')->where('service', 'morning')->first();
        $this->assertNotNull($service);
        $this->assertCount(2, $service->items);
    }
}
