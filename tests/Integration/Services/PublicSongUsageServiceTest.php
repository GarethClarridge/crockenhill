<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\Song;
use App\Services\Public\PublicSongUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
