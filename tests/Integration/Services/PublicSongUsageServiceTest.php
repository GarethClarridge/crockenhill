<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\SermonPublicationState;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SongUsageReport;
use App\Services\Public\PublicSongUsageService;
use App\Services\Song\SongUsageQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicSongUsageServiceTest extends TestCase
{
    use RefreshDatabase;

    private PublicSongUsageService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PublicSongUsageService::class);
    }

    #[Test]
    public function it_normalizes_supported_and_unknown_ranges(): void
    {
        $this->assertSame(PublicSongUsageService::RANGE_ALL, $this->service->normalizeRange(null));
        $this->assertSame(PublicSongUsageService::RANGE_ALL, $this->service->normalizeRange('unknown'));
        $this->assertSame(PublicSongUsageService::RANGE_THIS_YEAR, $this->service->normalizeRange('year'));
    }

    #[Test]
    public function it_returns_song_usage_stats(): void
    {
        $song = Song::factory()->create();
        $firstService = ChurchService::factory()->create(['date' => '2025-01-05']);
        $latestService = ChurchService::factory()->create(['date' => '2025-03-09']);

        ChurchServiceItem::factory()->create(['church_service_id' => $firstService->id, 'song_id' => $song->id, 'type' => 'songs']);
        ChurchServiceItem::factory()->create(['church_service_id' => $latestService->id, 'song_id' => $song->id, 'type' => 'songs']);

        $this->assertSame([
            'usage_count' => 2,
            'last_sung_date' => '2025-03-09',
        ], $this->service->statsForSong($song));
    }

    #[Test]
    public function it_includes_unresolved_date_only_reports_without_inventing_a_service(): void
    {
        $song = Song::factory()->create();
        SongUsageReport::factory()->create([
            'song_id' => $song->id,
            'used_on' => '2007-06-17',
            'reported_service' => null,
            'reported_title' => 'Historic title',
        ]);

        $this->assertSame([
            'usage_count' => 1,
            'last_sung_date' => '2007-06-17',
        ], $this->service->statsForSong($song));

        $occurrence = $this->service->usageHistoryForSong($song)->sole();

        $this->assertSame('2007-06-17', $occurrence->date->toDateString());
        $this->assertNull($occurrence->service);
        $this->assertNull($occurrence->churchService);
        $this->assertSame('Historic title', $occurrence->title);
    }

    /**
     * F61's read-side boundary: importing hymn evidence is not publishing it. A quarantined
     * report is admin-visible and absent from every public read until the batch is released.
     */
    #[Test]
    public function it_withholds_quarantined_date_only_reports_from_public_reads(): void
    {
        $song = Song::factory()->create();
        $report = SongUsageReport::factory()->quarantined()->create([
            'song_id' => $song->id,
            'used_on' => '2007-06-17',
            'reported_service' => null,
            'reported_title' => 'Historic title',
        ]);

        $this->assertSame([
            'usage_count' => 0,
            'last_sung_date' => null,
        ], $this->service->statsForSong($song));
        $this->assertCount(0, $this->service->usageHistoryForSong($song));

        $this->assertSame(
            1,
            app(SongUsageQuery::class)->occurrences(publicOnly: false)->where('song_id', $song->id)->count(),
            'Admin song usage reads the evidence as soon as it is imported.',
        );

        $report->forceFill(['publication_state' => SermonPublicationState::Published])->save();

        $this->assertSame(1, $this->service->statsForSong($song)['usage_count']);
        $this->assertCount(1, $this->service->usageHistoryForSong($song));
    }

    #[Test]
    public function it_does_not_double_count_a_report_resolved_to_a_canonical_item(): void
    {
        $song = Song::factory()->create();
        $service = ChurchService::factory()->create(['date' => '2007-06-17']);
        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'song_id' => $song->id,
            'type' => 'songs',
        ]);
        SongUsageReport::factory()->create([
            'song_id' => $song->id,
            'used_on' => '2007-06-17',
            'resolved_church_service_item_id' => $item->id,
        ]);

        $this->assertSame(1, $this->service->statsForSong($song)['usage_count']);
        $this->assertCount(1, $this->service->usageHistoryForSong($song));
    }

    #[Test]
    public function it_returns_zero_stats_for_a_song_that_has_not_been_sung(): void
    {
        $stats = $this->service->statsForSong(Song::factory()->create());

        $this->assertSame(0, $stats['usage_count']);
        $this->assertNull($stats['last_sung_date']);
    }

    #[Test]
    public function it_returns_song_usage_history_newest_first(): void
    {
        $song = Song::factory()->create();
        $firstService = ChurchService::factory()->create(['date' => '2025-01-05']);
        $latestService = ChurchService::factory()->create(['date' => '2025-03-09']);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $firstService->id,
            'song_id' => $song->id,
            'type' => 'songs',
            'title' => 'First Use',
        ]);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $latestService->id,
            'song_id' => $song->id,
            'type' => 'songs',
            'title' => 'Second Use',
        ]);

        $history = $this->service->usageHistoryForSong($song);

        $this->assertSame(['Second Use', 'First Use'], $history->pluck('title')->all());
    }

    #[Test]
    public function it_excludes_unmatched_completed_livestream_usage(): void
    {
        $song = Song::factory()->create();
        $churchService = ChurchService::factory()->create(['date' => '2025-03-09']);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'song_id' => $song->id,
            'type' => 'songs',
        ]);
        MediaProcessingLog::factory()->livestream()->completed()->create([
            'church_service_id' => $churchService->id,
        ]);

        $this->assertSame(0, $this->service->statsForSong($song)['usage_count']);
        $this->assertCount(0, $this->service->usageHistoryForSong($song));
    }

    #[Test]
    public function it_includes_confirmed_completed_livestream_usage_in_stats_and_history(): void
    {
        $song = Song::factory()->create();
        $churchService = ChurchService::factory()->create(['date' => '2025-03-09']);
        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'song_id' => $song->id,
            'type' => 'songs',
            'title' => 'Confirmed livestream song',
        ]);
        $log = MediaProcessingLog::factory()->livestream()->completed()->create([
            'church_service_id' => $churchService->id,
        ]);
        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Song,
            'song_match_type' => ServiceSectionSongMatchType::Confirmed,
        ]);

        $this->assertSame(1, $this->service->statsForSong($song)['usage_count']);
        $this->assertSame([$item->id], $this->service->usageHistoryForSong($song)->pluck('sourceId')->all());
    }

    #[Test]
    #[DataProvider('eligibleProcessingLogStates')]
    public function it_keeps_order_of_service_usage_eligible_for_non_completed_livestream_logs(
        string $processingType,
        string $status,
    ): void {
        $song = Song::factory()->create();
        $churchService = ChurchService::factory()->create(['date' => '2025-03-09']);
        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'song_id' => $song->id,
            'type' => 'songs',
        ]);

        MediaProcessingLog::factory()->create([
            'church_service_id' => $churchService->id,
            'processing_type' => $processingType,
            'status' => $status,
        ]);

        $this->assertSame(1, $this->service->statsForSong($song)['usage_count']);
        $this->assertSame([$item->id], $this->service->usageHistoryForSong($song)->pluck('sourceId')->all());
    }

    /** @return array<string, array{string, string}> */
    public static function eligibleProcessingLogStates(): array
    {
        return [
            'failed livestream' => ['livestream', 'failed'],
            'pending livestream' => ['livestream', 'pending'],
            'processing livestream' => ['livestream', 'processing'],
            'completed audio' => ['audio', 'completed'],
        ];
    }
}
