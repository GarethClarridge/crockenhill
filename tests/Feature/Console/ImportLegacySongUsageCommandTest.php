<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ChurchServiceItemSource;
use App\Enums\ChurchServiceReviewState;
use App\Enums\SermonService;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\Song;
use App\Services\PublicSongUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImportLegacySongUsageCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function it_imports_legacy_play_date_rows_into_church_services_and_items(): void
    {
        Song::factory()->create([
            'id' => 29,
            'canonical_key' => 'legacy-song-29',
            'title' => 'First Legacy Song',
        ]);
        Song::factory()->create([
            'id' => 38,
            'canonical_key' => 'legacy-song-38',
            'title' => 'Second Legacy Song',
        ]);

        $path = $this->createLegacyDump([
            [1, 29, '2013-04-28', 'a'],
            [2, 38, '2013-04-28', 'a'],
            [3, 29, '2013-04-28', 'p'],
        ]);

        $this->artisan('service-tracking:import-legacy-song-usage', ['--path' => $path])
            ->assertExitCode(0)
            ->expectsOutputToContain('Legacy song usage import completed.');

        $this->assertDatabaseCount('church_services', 2);
        $this->assertDatabaseCount('church_service_items', 3);

        $morningService = ChurchService::query()
            ->where('date', '2013-04-28')
            ->where('service', SermonService::Morning->value)
            ->firstOrFail();

        $this->assertSame('legacy_play_date', $morningService->source);
        $this->assertSame(ChurchServiceReviewState::Reviewed, $morningService->review_state);
        $this->assertNotNull($morningService->manual_reviewed_at);

        $morningItems = ChurchServiceItem::query()
            ->where('church_service_id', $morningService->id)
            ->whereNull('deleted_at')
            ->orderBy('position')
            ->get();

        $this->assertCount(2, $morningItems);
        $this->assertSame([1, 2], $morningItems->pluck('position')->all());
        $this->assertSame([29, 38], $morningItems->pluck('song_id')->all());
        $this->assertSame(1, data_get($morningItems[0]->metadata, 'legacy_play_date_id'));
        $this->assertSame(2, data_get($morningItems[1]->metadata, 'legacy_play_date_id'));
        $this->assertSame(ServiceSectionType::SONG, $morningItems[0]->section_type);
        $this->assertSame(ChurchServiceItemSource::Manual, $morningItems[0]->source);

        $eveningService = ChurchService::query()
            ->where('date', '2013-04-28')
            ->where('service', SermonService::Evening->value)
            ->firstOrFail();

        $this->assertDatabaseHas('church_service_items', [
            'church_service_id' => $eveningService->id,
            'position' => 1,
            'song_id' => 29,
            'title' => 'First Legacy Song',
        ]);

        $usageStats = app(PublicSongUsageService::class)->statsForSong(
            Song::query()->findOrFail(29)
        );

        $this->assertSame(2, $usageStats['usage_count']);
        $this->assertSame('2013-04-28', $usageStats['last_sung_date']);

        $this->artisan('service-tracking:import-legacy-song-usage', ['--path' => $path])
            ->assertExitCode(0);

        $this->assertDatabaseCount('church_services', 2);
        $this->assertDatabaseCount('church_service_items', 3);
    }

    #[Test]
    public function it_merges_into_existing_services_without_duplicating_existing_song_rows(): void
    {
        $songOne = Song::factory()->create([
            'id' => 29,
            'canonical_key' => 'legacy-song-29',
            'title' => 'Existing Song',
        ]);
        $songTwo = Song::factory()->create([
            'id' => 38,
            'canonical_key' => 'legacy-song-38',
            'title' => 'Missing Song',
        ]);

        $churchService = ChurchService::factory()->create([
            'date' => '2013-04-28',
            'service' => SermonService::Morning->value,
            'source' => 'email',
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'section_type' => ServiceSectionType::SONG->value,
            'source' => ChurchServiceItemSource::Email->value,
            'title' => $songOne->title,
            'source_title' => $songOne->title,
            'openlp_search_title' => null,
            'song_id' => $songOne->id,
            'metadata' => [
                'section_type' => ServiceSectionType::SONG->value,
            ],
        ]);

        $path = $this->createLegacyDump([
            [1, 29, '2013-04-28', 'a'],
            [2, 38, '2013-04-28', 'a'],
        ]);

        $this->artisan('service-tracking:import-legacy-song-usage', ['--path' => $path])
            ->assertExitCode(0);

        $churchService->refresh();

        $this->assertSame('email', $churchService->source);

        $items = ChurchServiceItem::query()
            ->where('church_service_id', $churchService->id)
            ->whereNull('deleted_at')
            ->orderBy('position')
            ->get();

        $this->assertCount(2, $items);
        $this->assertSame([1, 2], $items->pluck('position')->all());
        $this->assertSame([$songOne->id, $songTwo->id], $items->pluck('song_id')->all());
        $this->assertNull(data_get($items[0]->metadata, 'legacy_play_date_id'));
        $this->assertSame(2, data_get($items[1]->metadata, 'legacy_play_date_id'));
    }

    #[Test]
    public function dry_run_reports_work_without_persisting_services_or_items(): void
    {
        Song::factory()->create([
            'id' => 29,
            'canonical_key' => 'legacy-song-29',
            'title' => 'Dry Run Song',
        ]);

        $path = $this->createLegacyDump([
            [1, 29, '2013-04-28', 'a'],
        ]);

        $this->artisan('service-tracking:import-legacy-song-usage', ['--path' => $path, '--dry-run' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('Dry run enabled. No database changes were written.');

        $this->assertDatabaseCount('church_services', 0);
        $this->assertDatabaseCount('church_service_items', 0);
    }

    #[Test]
    public function it_fails_when_the_dump_references_song_ids_that_are_not_available_locally(): void
    {
        $path = $this->createLegacyDump([
            [1, 999, '2013-04-28', 'a'],
        ]);

        $this->artisan('service-tracking:import-legacy-song-usage', ['--path' => $path])
            ->assertExitCode(1)
            ->expectsOutputToContain('do not exist in the local songs table');
    }

    /**
     * @param  list<array{0:int,1:int,2:string,3:'a'|'p'}>  $rows
     */
    private function createLegacyDump(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'legacy-play-date-');
        if ($path === false) {
            self::fail('Failed to allocate a temporary SQL dump.');
        }

        $this->temporaryFiles[] = $path;

        $values = array_map(
            static fn (array $row): string => sprintf(
                "(%d,'%d','%s','%s','2026-03-27 00:00:00','2026-03-27 00:00:00')",
                $row[0],
                $row[1],
                $row[2],
                $row[3]
            ),
            $rows
        );

        $contents = implode(PHP_EOL, [
            '-- Minimal legacy dump fixture',
            'INSERT INTO `play_date` VALUES '.implode(',', $values).';',
            '',
        ]);

        file_put_contents($path, $contents);

        return $path;
    }
}
