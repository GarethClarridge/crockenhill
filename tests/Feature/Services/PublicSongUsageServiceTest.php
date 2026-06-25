<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
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
    public function it_excludes_songs_with_no_usage(): void
    {
        Song::factory()->create(['title' => 'Unused Song']);

        $results = $this->service->query()->get();

        $this->assertFalse($results->contains('title', 'Unused Song'));
    }

    #[Test]
    public function it_includes_songs_with_oos_usage_and_no_livestream(): void
    {
        $song = Song::factory()->create(['title' => 'OoS Song']);
        $churchService = ChurchService::factory()->create(['date' => now()]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'song_id' => $song->id,
            'type' => 'songs',
        ]);

        $results = $this->service->query()->get();

        $this->assertTrue($results->contains('title', 'OoS Song'));
    }

    #[Test]
    public function it_excludes_oos_song_if_livestream_completed_but_song_not_confirmed(): void
    {
        $song = Song::factory()->create(['title' => 'Unconfirmed Song']);
        $churchService = ChurchService::factory()->create(['date' => now()]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'song_id' => $song->id,
            'type' => 'songs',
        ]);

        // Completed livestream log exists for this service
        MediaProcessingLog::factory()->livestream()->completed()->create([
            'church_service_id' => $churchService->id,
        ]);

        // But NO confirmed service section for this item exists

        $results = $this->service->query()->get();

        $this->assertFalse($results->contains('title', 'Unconfirmed Song'));
    }

    #[Test]
    public function it_includes_oos_song_if_livestream_completed_and_song_is_confirmed(): void
    {
        $song = Song::factory()->create(['title' => 'Confirmed Song']);
        $churchService = ChurchService::factory()->create(['date' => now()]);

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
            'section_type' => ServiceSectionType::Song,
            'song_match_type' => ServiceSectionSongMatchType::Confirmed,
        ]);

        $results = $this->service->query()->get();

        $this->assertTrue($results->contains('title', 'Confirmed Song'));
    }

    #[Test]
    public function it_includes_oos_song_if_livestream_failed(): void
    {
        $song = Song::factory()->create(['title' => 'Failed Livestream Song']);
        $churchService = ChurchService::factory()->create(['date' => now()]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'song_id' => $song->id,
            'type' => 'songs',
        ]);

        MediaProcessingLog::factory()->livestream()->failed()->create([
            'church_service_id' => $churchService->id,
        ]);

        $results = $this->service->query()->get();

        $this->assertTrue($results->contains('title', 'Failed Livestream Song'));
    }

    #[Test]
    public function it_calculates_correct_stats_for_song(): void
    {
        $song = Song::factory()->create();

        // Usage 1: 10 days ago
        $service1 = ChurchService::factory()->create(['date' => now()->subDays(10)]);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $service1->id,
            'song_id' => $song->id,
            'type' => 'songs',
        ]);

        // Usage 2: 5 days ago
        $service2 = ChurchService::factory()->create(['date' => now()->subDays(5)]);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $service2->id,
            'song_id' => $song->id,
            'type' => 'songs',
        ]);

        $stats = $this->service->statsForSong($song);

        $this->assertEquals(2, $stats['usage_count']);
        $this->assertEquals(now()->subDays(5)->format('Y-m-d'), $stats['last_sung_date']);
    }

    #[Test]
    public function it_returns_usage_history_in_correct_order(): void
    {
        $song = Song::factory()->create();

        $serviceOld = ChurchService::factory()->create(['date' => now()->subDays(10)]);
        $itemOld = ChurchServiceItem::factory()->create([
            'church_service_id' => $serviceOld->id,
            'song_id' => $song->id,
            'type' => 'songs',
        ]);

        $serviceNew = ChurchService::factory()->create(['date' => now()->subDays(5)]);
        $itemNew = ChurchServiceItem::factory()->create([
            'church_service_id' => $serviceNew->id,
            'song_id' => $song->id,
            'type' => 'songs',
        ]);

        $history = $this->service->usageHistoryForSong($song);

        $this->assertCount(2, $history);
        $this->assertEquals($itemNew->id, $history[0]->id);
        $this->assertEquals($itemOld->id, $history[1]->id);
    }

    #[Test]
    public function it_filters_by_this_year_range(): void
    {
        $song = Song::factory()->create(['title' => 'Recent Song']);
        $songOld = Song::factory()->create(['title' => 'Old Song']);

        // This year
        $serviceRecent = ChurchService::factory()->create(['date' => now()]);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $serviceRecent->id,
            'song_id' => $song->id,
            'type' => 'songs',
        ]);

        // Last year
        $serviceOld = ChurchService::factory()->create(['date' => now()->subYear()]);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $serviceOld->id,
            'song_id' => $songOld->id,
            'type' => 'songs',
        ]);

        // Query all
        $all = $this->service->query(PublicSongUsageService::RANGE_ALL)->get();
        $this->assertCount(2, $all);

        // Query this year
        $thisYear = $this->service->query(PublicSongUsageService::RANGE_THIS_YEAR)->get();
        $this->assertCount(1, $thisYear);
        $this->assertEquals('Recent Song', $thisYear->first()->title);
    }
}
