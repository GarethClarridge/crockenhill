<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SongAuthor;
use App\Models\SongUsageReport;
use App\Services\Public\PublicSongCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicSongCatalogServiceTest extends TestCase
{
    use RefreshDatabase;

    private PublicSongCatalogService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PublicSongCatalogService::class);
    }

    // ── all range ─────────────────────────────────────────────────────────

    #[Test]
    public function all_range_includes_songs_with_zero_usage(): void
    {
        $song = Song::factory()->create(['title' => 'Zero Usage Song']);

        $ids = $this->service->query('all')->pluck('id');

        $this->assertTrue($ids->contains($song->id));
    }

    #[Test]
    public function all_range_includes_songs_with_qualifying_usage(): void
    {
        $song = Song::factory()->create(['title' => 'Used Song']);

        $service = ChurchService::factory()->create([
            'date' => '2026-01-15',
            'service' => SermonService::Morning,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'type' => 'songs',
            'song_id' => $song->id,
        ]);

        $ids = $this->service->query('all')->pluck('id');

        $this->assertTrue($ids->contains($song->id));
    }

    #[Test]
    public function all_range_attaches_usage_count_and_last_sung_date(): void
    {
        $song = Song::factory()->create(['title' => 'Usage Count Song']);

        foreach (['2026-01-05', '2026-02-10'] as $date) {
            $churchService = ChurchService::factory()->create([
                'date' => $date,
                'service' => SermonService::Morning,
            ]);

            ChurchServiceItem::factory()->create([
                'church_service_id' => $churchService->id,
                'type' => 'songs',
                'song_id' => $song->id,
            ]);
        }

        $result = $this->service->query('all')->firstWhere('id', $song->id);

        $this->assertNotNull($result);
        $this->assertSame(2, (int) $result->usage_count);
        $this->assertSame('2026-02-10', (string) $result->last_sung_date);
    }

    #[Test]
    public function all_range_includes_date_only_reports_in_usage_stats(): void
    {
        $song = Song::factory()->create(['title' => 'Historic Usage Song']);
        SongUsageReport::factory()->create([
            'song_id' => $song->id,
            'used_on' => '2007-06-17',
        ]);

        $result = $this->service->query('all')->firstWhere('id', $song->id);

        $this->assertNotNull($result);
        $this->assertSame(1, (int) $result->usage_count);
        $this->assertSame('2007-06-17', (string) $result->last_sung_date);
    }

    #[Test]
    public function all_range_gives_zero_usage_count_to_songs_never_sung(): void
    {
        $song = Song::factory()->create(['title' => 'Never Sung Song']);

        $result = $this->service->query('all')->firstWhere('id', $song->id);

        $this->assertNotNull($result);
        $this->assertSame(0, (int) $result->usage_count);
        $this->assertNull($result->last_sung_date);
    }

    #[Test]
    public function all_range_orders_by_usage_count_descending_then_last_sung_then_title(): void
    {
        $highUsage = Song::factory()->create(['title' => 'High Usage']);
        $lowUsage = Song::factory()->create(['title' => 'Low Usage']);
        $unused = Song::factory()->create(['title' => 'Unused']);

        foreach (['2026-01-01', '2026-01-08'] as $date) {
            $churchService = ChurchService::factory()->create([
                'date' => $date,
                'service' => SermonService::Morning,
            ]);

            ChurchServiceItem::factory()->create([
                'church_service_id' => $churchService->id,
                'type' => 'songs',
                'song_id' => $highUsage->id,
            ]);
        }

        $churchService = ChurchService::factory()->create([
            'date' => '2026-01-15',
            'service' => SermonService::Morning,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'type' => 'songs',
            'song_id' => $lowUsage->id,
        ]);

        $ids = $this->service->query('all')->get()->pluck('id');

        $highPos = $ids->search($highUsage->id);
        $lowPos = $ids->search($lowUsage->id);
        $unusedPos = $ids->search($unused->id);

        $this->assertLessThan($lowPos, $highPos);
        $this->assertLessThan($unusedPos, $lowPos);
    }

    // ── recent range (last 3 years) ───────────────────────────────────────

    #[Test]
    public function recent_range_excludes_songs_not_sung_in_last_3_years(): void
    {
        $oldSong = Song::factory()->create(['title' => 'Old Song']);

        $service = ChurchService::factory()->create([
            'date' => '2022-06-01',
            'service' => SermonService::Morning,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'type' => 'songs',
            'song_id' => $oldSong->id,
        ]);

        $ids = $this->service->query('recent')->pluck('id');

        $this->assertFalse($ids->contains($oldSong->id));
    }

    #[Test]
    public function recent_range_excludes_songs_with_zero_usage(): void
    {
        $song = Song::factory()->create(['title' => 'Zero Usage Recent']);

        $ids = $this->service->query('recent')->pluck('id');

        $this->assertFalse($ids->contains($song->id));
    }

    #[Test]
    public function recent_range_includes_songs_sung_within_last_3_years(): void
    {
        $song = Song::factory()->create(['title' => 'Recent Song']);

        $churchService = ChurchService::factory()->create([
            'date' => now()->format('Y-01-20'),
            'service' => SermonService::Morning,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'type' => 'songs',
            'song_id' => $song->id,
        ]);

        $ids = $this->service->query('recent')->pluck('id');

        $this->assertTrue($ids->contains($song->id));
    }

    #[Test]
    public function recent_range_counts_only_last_3_years_usage(): void
    {
        $song = Song::factory()->create(['title' => 'Dual Period Song']);

        $recentDate = now()->subYear()->format('Y-m-d');
        $oldDate = now()->subYears(4)->format('Y-m-d');

        foreach ([$recentDate, $oldDate] as $date) {
            $churchService = ChurchService::factory()->create([
                'date' => $date,
                'service' => SermonService::Morning,
            ]);

            ChurchServiceItem::factory()->create([
                'church_service_id' => $churchService->id,
                'type' => 'songs',
                'song_id' => $song->id,
            ]);
        }

        $result = $this->service->query('recent')->firstWhere('id', $song->id);

        $this->assertNotNull($result);
        $this->assertSame(1, (int) $result->usage_count);
        $this->assertSame($recentDate, (string) $result->last_sung_date);
    }

    // ── livestream policy ─────────────────────────────────────────────────

    #[Test]
    public function livestreamed_service_only_counts_confirmed_section_matches(): void
    {
        $countedSong = Song::factory()->create(['title' => 'Confirmed Match Song']);
        $oosOnlySong = Song::factory()->create(['title' => 'OOS Only Song']);

        $churchService = ChurchService::factory()->create([
            'date' => '2026-02-08',
            'service' => SermonService::Morning,
        ]);

        $countedItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'type' => 'songs',
            'song_id' => $countedSong->id,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'type' => 'songs',
            'song_id' => $oosOnlySong->id,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
            'status' => ProcessingStatus::Completed,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => $countedItem->id,
            'section_type' => ServiceSectionType::Song,
            'song_match_type' => ServiceSectionSongMatchType::Confirmed->value,
            'metadata' => ['oos_alignment' => []],
        ]);

        $ids = $this->service->query('all')->pluck('id');

        $this->assertTrue($ids->contains($countedSong->id));

        $result = $this->service->query('all')->firstWhere('id', $countedSong->id);
        $this->assertSame(1, (int) $result->usage_count);

        $oosResult = $this->service->query('all')->firstWhere('id', $oosOnlySong->id);
        $this->assertSame(0, (int) $oosResult->usage_count);
    }

    // ── qualification policy: non-completed-livestream states ──

    #[Test]
    public function failed_livestream_log_does_not_disqualify_oos_items(): void
    {
        $song = Song::factory()->create(['title' => 'Failed Log Song']);

        $churchService = ChurchService::factory()->create(['date' => now()->subMonths(2)]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'type' => 'songs',
            'song_id' => $song->id,
        ]);

        MediaProcessingLog::factory()->livestream()->failed()->create([
            'church_service_id' => $churchService->id,
        ]);

        $result = $this->service->query('all')->firstWhere('id', $song->id);

        $this->assertNotNull($result);
        $this->assertSame(1, (int) $result->usage_count);
    }

    #[Test]
    public function pending_livestream_log_does_not_disqualify_oos_items(): void
    {
        $song = Song::factory()->create(['title' => 'Pending Log Song']);

        $churchService = ChurchService::factory()->create(['date' => now()->subMonths(2)]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'type' => 'songs',
            'song_id' => $song->id,
        ]);

        MediaProcessingLog::factory()->livestream()->pending()->create([
            'church_service_id' => $churchService->id,
        ]);

        $result = $this->service->query('all')->firstWhere('id', $song->id);

        $this->assertNotNull($result);
        $this->assertSame(1, (int) $result->usage_count);
    }

    #[Test]
    public function processing_livestream_log_does_not_disqualify_oos_items(): void
    {
        $song = Song::factory()->create(['title' => 'Processing Log Song']);

        $churchService = ChurchService::factory()->create(['date' => now()->subMonths(2)]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'type' => 'songs',
            'song_id' => $song->id,
        ]);

        MediaProcessingLog::factory()->livestream()->processing()->create([
            'church_service_id' => $churchService->id,
        ]);

        $result = $this->service->query('all')->firstWhere('id', $song->id);

        $this->assertNotNull($result);
        $this->assertSame(1, (int) $result->usage_count);
    }

    #[Test]
    public function completed_audio_log_does_not_disqualify_oos_items(): void
    {
        $song = Song::factory()->create(['title' => 'Audio Log Song']);

        $churchService = ChurchService::factory()->create(['date' => now()->subMonths(2)]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'type' => 'songs',
            'song_id' => $song->id,
        ]);

        MediaProcessingLog::factory()->audio()->completed()->create([
            'church_service_id' => $churchService->id,
        ]);

        $result = $this->service->query('all')->firstWhere('id', $song->id);

        $this->assertNotNull($result);
        $this->assertSame(1, (int) $result->usage_count);
    }

    #[Test]
    public function completed_livestream_without_confirmed_section_excludes_oos_item(): void
    {
        $song = Song::factory()->create(['title' => 'No Section Song']);

        $churchService = ChurchService::factory()->create(['date' => now()->subMonths(2)]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'type' => 'songs',
            'song_id' => $song->id,
        ]);

        MediaProcessingLog::factory()->livestream()->completed()->create([
            'church_service_id' => $churchService->id,
        ]);

        $result = $this->service->query('all')->firstWhere('id', $song->id);

        $this->assertNotNull($result);
        $this->assertSame(0, (int) $result->usage_count);
    }

    // ── normalizeRange ────────────────────────────────────────────────────

    #[Test]
    public function normalize_range_returns_recent_for_recent_input(): void
    {
        $this->assertSame('recent', $this->service->normalizeRange('recent'));
    }

    #[Test]
    public function normalize_range_returns_all_for_unknown_input(): void
    {
        $this->assertSame('all', $this->service->normalizeRange('weekly'));
        $this->assertSame('all', $this->service->normalizeRange(null));
        $this->assertSame('all', $this->service->normalizeRange(''));
    }

    // ── tokenize ──────────────────────────────────────────────────────────

    #[Test]
    public function tokenize_splits_on_whitespace_and_lowercases(): void
    {
        $tokens = $this->service->tokenize('Amazing Grace');

        $this->assertSame(['amazing', 'grace'], $tokens->all());
    }

    #[Test]
    public function tokenize_deduplicates_tokens(): void
    {
        $tokens = $this->service->tokenize('grace grace GRACE');

        $this->assertSame(['grace'], $tokens->all());
    }

    #[Test]
    public function tokenize_returns_empty_collection_for_blank_string(): void
    {
        $this->assertTrue($this->service->tokenize('')->isEmpty());
        $this->assertTrue($this->service->tokenize('   ')->isEmpty());
    }

    // ── search filtering ──────────────────────────────────────────────────

    #[Test]
    public function search_matches_by_title(): void
    {
        $song = Song::factory()->create(['title' => 'Amazing Grace']);
        Song::factory()->create(['title' => 'How Great Thou Art']);

        $ids = $this->service->query('all', 'amazing')->pluck('id');

        $this->assertTrue($ids->contains($song->id));
        $this->assertFalse($ids->contains(Song::query()->where('title', 'How Great Thou Art')->value('id')));
    }

    #[Test]
    public function search_matches_by_alternate_title(): void
    {
        $song = Song::factory()->create([
            'title' => 'A Mighty Fortress',
            'alternate_title' => 'Ein Feste Burg',
        ]);

        $ids = $this->service->query('all', 'feste')->pluck('id');

        $this->assertTrue($ids->contains($song->id));
    }

    #[Test]
    public function search_matches_by_ccli_number(): void
    {
        $song = Song::factory()->create(['ccli_number' => '1234567']);

        $ids = $this->service->query('all', '1234567')->pluck('id');

        $this->assertTrue($ids->contains($song->id));
    }

    #[Test]
    public function search_matches_by_author(): void
    {
        $author = SongAuthor::factory()->create(['display_name' => 'Charles Wesley']);
        $song = Song::factory()->create(['title' => 'O for a Thousand Tongues']);
        $song->authors()->attach($author->id);

        $ids = $this->service->query('all', 'Wesley')->pluck('id');

        $this->assertTrue($ids->contains($song->id));
    }

    #[Test]
    public function search_requires_all_tokens_to_match(): void
    {
        $song = Song::factory()->create(['title' => 'Amazing Grace']);

        // Both tokens present in title — should match.
        $ids = $this->service->query('all', 'amazing grace')->pluck('id');
        $this->assertTrue($ids->contains($song->id));

        // Second token not in title or anywhere else — should not match.
        $ids = $this->service->query('all', 'amazing xyzzy_nomatch')->pluck('id');
        $this->assertFalse($ids->contains($song->id));
    }

    #[Test]
    public function empty_search_returns_full_catalogue(): void
    {
        $song = Song::factory()->create(['title' => 'Any Song']);

        $ids = $this->service->query('all', '')->pluck('id');

        $this->assertTrue($ids->contains($song->id));
    }
}
