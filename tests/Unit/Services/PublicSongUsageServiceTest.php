<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Services\PublicSongUsageService;
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
    public function test_normalize_range_returns_all_for_unknown_values(): void
    {
        $this->assertSame(PublicSongUsageService::RANGE_ALL, $this->service->normalizeRange(null));
        $this->assertSame(PublicSongUsageService::RANGE_ALL, $this->service->normalizeRange(''));
        $this->assertSame(PublicSongUsageService::RANGE_ALL, $this->service->normalizeRange('unknown'));
    }

    #[Test]
    public function test_normalize_range_returns_year_for_year_value(): void
    {
        $this->assertSame(PublicSongUsageService::RANGE_THIS_YEAR, $this->service->normalizeRange('year'));
    }

    #[Test]
    public function test_query_includes_songs_from_services_without_a_processing_log(): void
    {
        $song = Song::factory()->create();
        $churchService = ChurchService::factory()->create(['date' => now()->subMonths(2)]);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'song_id' => $song->id,
            'type' => 'songs',
        ]);

        $results = $this->service->query()->get();

        $this->assertCount(1, $results);
        $this->assertSame($song->id, $results->first()->id);
    }

    #[Test]
    public function test_query_excludes_legacy_json_only_song_match_state_from_livestream_reporting(): void
    {
        $song = Song::factory()->create();
        $churchService = ChurchService::factory()->create(['date' => now()->subMonths(2)]);
        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'song_id' => $song->id,
            'type' => 'songs',
        ]);

        $log = MediaProcessingLog::factory()->livestream()->completed()->create([
            'church_service_id' => $churchService->id,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::SONG,
            'metadata' => [
                'oos_alignment' => [
                    'song_match_type' => 'confirmed',
                ],
            ],
        ]);

        $results = $this->service->query()->get();

        $this->assertCount(0, $results);
    }

    #[Test]
    public function test_query_includes_livestreamed_songs_matched_via_promoted_song_match_type_column(): void
    {
        $song = Song::factory()->create();
        $churchService = ChurchService::factory()->create(['date' => now()->subMonths(2)]);
        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'song_id' => $song->id,
            'type' => 'songs',
        ]);

        $log = MediaProcessingLog::factory()->livestream()->completed()->create([
            'church_service_id' => $churchService->id,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::SONG,
            'song_match_type' => ServiceSectionSongMatchType::CONFIRMED->value,
            'metadata' => ['oos_alignment' => []],
        ]);

        $results = $this->service->query()->get();

        $this->assertCount(1, $results);
        $this->assertSame($song->id, $results->first()->id);
    }

    #[Test]
    public function test_query_excludes_songs_from_livestreamed_services_not_matched_by_a_section(): void
    {
        $song = Song::factory()->create();
        $churchService = ChurchService::factory()->create(['date' => now()->subMonths(2)]);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'song_id' => $song->id,
            'type' => 'songs',
        ]);

        // A completed livestream log exists but no matching service section for this item
        MediaProcessingLog::factory()->livestream()->completed()->create([
            'church_service_id' => $churchService->id,
        ]);

        $results = $this->service->query()->get();

        $this->assertCount(0, $results);
    }

    #[Test]
    public function test_query_excludes_review_only_inferred_song_matches_from_livestreamed_services(): void
    {
        $song = Song::factory()->create();
        $churchService = ChurchService::factory()->create(['date' => now()->subMonths(2)]);
        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'song_id' => $song->id,
            'type' => 'songs',
        ]);

        $log = MediaProcessingLog::factory()->livestream()->completed()->create([
            'church_service_id' => $churchService->id,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::SONG,
            'needs_manual_review' => true,
            'song_match_type' => ServiceSectionSongMatchType::INFERRED->value,
            'metadata' => ['oos_alignment' => []],
        ]);

        $results = $this->service->query()->get();

        $this->assertCount(0, $results);
    }

    #[Test]
    public function test_query_excludes_livestreamed_songs_without_an_explicit_confirmed_match_type(): void
    {
        $song = Song::factory()->create();
        $churchService = ChurchService::factory()->create(['date' => now()->subMonths(2)]);
        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'song_id' => $song->id,
            'type' => 'songs',
        ]);

        $log = MediaProcessingLog::factory()->livestream()->completed()->create([
            'church_service_id' => $churchService->id,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::SONG,
            'needs_manual_review' => false,
            'metadata' => [
                'oos_alignment' => [],
            ],
        ]);

        $results = $this->service->query()->get();

        $this->assertCount(0, $results);
    }

    #[Test]
    public function test_query_filters_by_year_when_range_is_year(): void
    {
        $song = Song::factory()->create();

        // This year
        $thisYearService = ChurchService::factory()->create(['date' => now()->startOfYear()]);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $thisYearService->id,
            'song_id' => $song->id,
            'type' => 'songs',
        ]);

        // Last year
        $lastYearService = ChurchService::factory()->create(['date' => now()->subYear()->startOfYear()]);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $lastYearService->id,
            'song_id' => $song->id,
            'type' => 'songs',
        ]);

        $allResults = $this->service->query(PublicSongUsageService::RANGE_ALL)->get();
        $yearResults = $this->service->query(PublicSongUsageService::RANGE_THIS_YEAR)->get();

        $this->assertSame(2, (int) $allResults->first()->usage_count);
        $this->assertSame(1, (int) $yearResults->first()->usage_count);
    }

    #[Test]
    public function test_stats_for_song_returns_correct_count_and_last_date(): void
    {
        $song = Song::factory()->create();

        $service1 = ChurchService::factory()->create(['date' => '2025-01-05']);
        ChurchServiceItem::factory()->create(['church_service_id' => $service1->id, 'song_id' => $song->id, 'type' => 'songs']);

        $service2 = ChurchService::factory()->create(['date' => '2025-03-09']);
        ChurchServiceItem::factory()->create(['church_service_id' => $service2->id, 'song_id' => $song->id, 'type' => 'songs']);

        $stats = $this->service->statsForSong($song);

        $this->assertSame(2, $stats['usage_count']);
        $this->assertSame('2025-03-09', $stats['last_sung_date']);
    }

    #[Test]
    public function test_stats_for_song_returns_zero_when_never_sung(): void
    {
        $song = Song::factory()->create();

        $stats = $this->service->statsForSong($song);

        $this->assertSame(0, $stats['usage_count']);
        $this->assertNull($stats['last_sung_date']);
    }

    #[Test]
    public function test_usage_history_for_song_returns_items_newest_first(): void
    {
        $song = Song::factory()->create();

        $service1 = ChurchService::factory()->create(['date' => '2025-01-05']);
        ChurchServiceItem::factory()->create(['church_service_id' => $service1->id, 'song_id' => $song->id, 'type' => 'songs', 'title' => 'First Use']);

        $service2 = ChurchService::factory()->create(['date' => '2025-03-09']);
        ChurchServiceItem::factory()->create(['church_service_id' => $service2->id, 'song_id' => $song->id, 'type' => 'songs', 'title' => 'Second Use']);

        $history = $this->service->usageHistoryForSong($song);

        $this->assertCount(2, $history);
        $this->assertSame('Second Use', $history->first()->title);
        $this->assertSame('First Use', $history->last()->title);
    }

    #[Test]
    public function test_usage_history_excludes_services_with_unmatched_livestream(): void
    {
        $song = Song::factory()->create();
        $churchService = ChurchService::factory()->create(['date' => '2025-03-09']);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'song_id' => $song->id,
            'type' => 'songs',
        ]);

        // Completed livestream exists but the item was never matched to a section
        MediaProcessingLog::factory()->livestream()->completed()->create([
            'church_service_id' => $churchService->id,
        ]);

        $history = $this->service->usageHistoryForSong($song);

        $this->assertCount(0, $history);
    }
}
