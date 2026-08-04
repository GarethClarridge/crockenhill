<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SongVideo;
use App\Services\Public\PublicChurchServiceArchiveService;
use App\Services\Public\PublicSongUsageService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicChurchServiceArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'service-tracking.enabled' => true,
            'church.sermons.childrens_talks.public' => true,
            'church.services.public_from' => null,
            'media-processing.storage.sermon_disk' => 'public',
        ]);

        Storage::fake('public');
    }

    #[Test]
    public function published_service_history_links_service_sermon_talk_and_songs_without_private_evidence(): void
    {
        [$service, $sermon, $childrensTalk, $song] = $this->processedService();

        $this->get(route('church.services.index'))
            ->assertOk()
            ->assertSee('Church services')
            ->assertSee(route('church.services.show', [
                'date' => $service->date->format('Y-m-d'),
                'service' => $service->service->value,
            ]), false);

        $this->get($this->showUrl($service))
            ->assertOk()
            ->assertSee($service->service->label().' service')
            ->assertSee($sermon->title)
            ->assertSee(route('sermons.show.dated', [
                'year' => $sermon->date->year,
                'month' => $sermon->date->format('m'),
                'sermon' => $sermon->slug,
            ]), false)
            ->assertSee($childrensTalk->title)
            ->assertSee(route('childrens-corner.show', $childrensTalk->slug), false)
            ->assertSee($song->title)
            ->assertSee(route('church.songs.show', $song->slug), false)
            ->assertSee('Isaiah 53:1-6')
            ->assertSee('song-performance.mp4', false)
            ->assertDontSee('Notices2024Looped.pptx')
            ->assertDontSee('scenechange:Lecturn')
            ->assertDontSee('Prayer for the Hurst family');
    }

    /**
     * The regression that motivated resolving sermons from the sermons table.
     *
     * No publication handler is registered for the `sermon` section type, so
     * `published_sermon_id` is null on every section in production. An archive
     * keyed on it renders no sermon at all.
     */
    #[Test]
    public function sermons_appear_even_though_no_section_is_ever_published_for_them(): void
    {
        [$service, $sermon] = $this->processedService();

        $this->assertSame(
            0,
            ServiceSection::query()
                ->whereIn('section_type', [ServiceSectionType::Sermon, ServiceSectionType::ChildrensTalk])
                ->where(fn ($query) => $query
                    ->whereNotNull('published_sermon_id')
                    ->orWhere('publication_status', ServiceSectionPublicationStatus::Published))
                ->count(),
            'Fixture must not rely on a publication state the pipeline cannot produce: '
            .'config/media-processing.php registers no handler for the sermon section type.',
        );

        $this->get($this->showUrl($service))
            ->assertOk()
            ->assertSee($sermon->title);
    }

    /**
     * The §14 acceptance criterion: "Public song usage agrees with service history."
     */
    #[Test]
    public function song_usage_history_and_service_history_agree(): void
    {
        [$service, , , $song] = $this->processedService();

        $usage = app(PublicSongUsageService::class)->usageHistoryForSong($song);

        $this->assertCount(1, $usage, 'The song should be counted as sung at this service.');

        $titles = app(PublicChurchServiceArchiveService::class)
            ->publicItems($service)
            ->pluck('title');

        $this->assertTrue(
            $titles->contains($song->title),
            'A song counted in usage history must appear on the service it was counted for.',
        );
    }

    /**
     * The inverse: once a completed livestream run withholds a song from usage
     * history, the service page must withhold it too.
     */
    #[Test]
    public function a_song_excluded_from_usage_history_is_excluded_from_service_history(): void
    {
        [$service, , , $song] = $this->processedService();

        $unconfirmed = Song::factory()->create(['title' => 'Unconfirmed Hymn', 'slug' => 'unconfirmed-hymn']);
        ChurchServiceItem::factory()->song()->create([
            'church_service_id' => $service->id,
            'position' => 20,
            'title' => $unconfirmed->title,
            'song_id' => $unconfirmed->id,
        ]);

        $usageTitles = app(PublicSongUsageService::class)
            ->usageHistoryForSong($unconfirmed)
            ->pluck('title');

        $this->assertCount(0, $usageTitles, 'A completed livestream run without a confirmed match excludes the item.');

        $this->get($this->showUrl($service))
            ->assertOk()
            ->assertDontSee($unconfirmed->title);
    }

    #[Test]
    public function services_without_publication_safe_content_are_neither_listed_nor_served(): void
    {
        $bare = ChurchService::factory()->create([
            'date' => '2026-06-14',
            'service' => SermonService::Morning,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $bare->id,
            'position' => 1,
            'title' => 'Notices2024Looped.pptx',
            'type' => 'presentations',
        ]);

        $this->get(route('church.services.index'))
            ->assertOk()
            ->assertDontSee($bare->date->format('j F Y'));

        $this->get($this->showUrl($bare))->assertNotFound();
    }

    #[Test]
    public function services_before_the_public_era_boundary_are_withheld(): void
    {
        [$service] = $this->processedService();

        config(['church.services.public_from' => '2026-01-01']);

        $this->get(route('church.services.index'))
            ->assertOk()
            ->assertSee($service->date->format('j F Y'));

        config(['church.services.public_from' => '2030-01-01']);

        $this->get(route('church.services.index'))
            ->assertOk()
            ->assertDontSee($service->date->format('j F Y'));

        $this->get($this->showUrl($service))->assertNotFound();
    }

    #[Test]
    public function order_follows_the_detected_service_order_not_the_item_positions(): void
    {
        [$service] = $this->processedService();

        $kinds = app(PublicChurchServiceArchiveService::class)
            ->publicItems($service)
            ->pluck('kind')
            ->all();

        // Detected section order is song(1), childrens_talk(2), scripture(3),
        // sermon(4), song(5) — not the item positions, which interleave differently.
        $this->assertSame(
            ['song', 'childrens_talk', 'scripture', 'sermon', 'song'],
            $kinds,
        );
    }

    #[Test]
    public function planned_only_services_order_by_item_position(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-07-19',
            'service' => SermonService::Evening,
        ]);

        $second = Song::factory()->create(['title' => 'Second Song', 'slug' => 'second-song']);
        $first = Song::factory()->create(['title' => 'First Song', 'slug' => 'first-song']);

        ChurchServiceItem::factory()->song()->create([
            'church_service_id' => $service->id,
            'position' => 2,
            'title' => $second->title,
            'song_id' => $second->id,
        ]);
        ChurchServiceItem::factory()->song()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'title' => $first->title,
            'song_id' => $first->id,
        ]);

        $items = app(PublicChurchServiceArchiveService::class)->publicItems($service);

        $this->assertSame(['First Song', 'Second Song'], $items->pluck('title')->all());
        $this->assertTrue($items->every(fn (array $item): bool => $item['planned_only'] === true));
    }

    #[Test]
    public function archive_filters_by_year_and_service_and_excludes_future_services(): void
    {
        $morning = $this->serviceWithScripture('2026-06-14', SermonService::Morning, 'Psalm 1:1-6');
        $evening = $this->serviceWithScripture('2026-06-14', SermonService::Evening, 'Psalm 2:1-12');
        $otherYear = $this->serviceWithScripture('2025-06-14', SermonService::Morning, 'Psalm 3:1-8');
        $future = $this->serviceWithScripture('2027-06-14', SermonService::Morning, 'Psalm 4:1-8');

        $this->get(route('church.services.index', [
            'year' => 2026,
            'service' => SermonService::Morning->value,
        ]))
            ->assertOk()
            ->assertSee($morning->date->format('j F Y'))
            ->assertDontSee($evening->date->format('j F Y').' — Evening service')
            ->assertDontSee($otherYear->date->format('j F Y'))
            ->assertDontSee($future->date->format('j F Y'));
    }

    #[Test]
    public function private_childrens_talks_are_omitted_from_public_service_history(): void
    {
        config(['church.sermons.childrens_talks.public' => false]);

        [$service, , $childrensTalk] = $this->processedService();

        $this->get($this->showUrl($service))
            ->assertOk()
            ->assertDontSee($childrensTalk->title)
            ->assertDontSee(route('childrens-corner.show', $childrensTalk->slug), false);
    }

    #[Test]
    public function performance_video_is_withheld_until_its_section_is_published(): void
    {
        [$service] = $this->processedService();

        // All three columns move together: a check constraint ties publication
        // status to published_at and published_sermon_id.
        ServiceSection::query()
            ->where('section_type', ServiceSectionType::Song)
            ->update([
                'publication_status' => ServiceSectionPublicationStatus::NotApplicable,
                'published_at' => null,
                'published_sermon_id' => null,
            ]);

        $this->get($this->showUrl($service))
            ->assertOk()
            ->assertDontSee('song-performance.mp4', false);
    }

    /**
     * The canonical URL is (date, service). That is only unambiguous because the
     * schema enforces it — pin the assumption so a migration relaxing the index
     * cannot silently make the archive serve an arbitrary one of two services.
     */
    #[Test]
    public function the_canonical_date_and_service_pair_is_unique(): void
    {
        [$existing] = $this->processedService();

        $this->expectException(UniqueConstraintViolationException::class);

        ChurchService::factory()->create([
            'date' => $existing->date,
            'service' => $existing->service,
        ]);
    }

    #[Test]
    public function the_detail_page_holds_its_query_count_flat(): void
    {
        [$service] = $this->processedService();

        for ($position = 30; $position < 40; $position++) {
            $song = Song::factory()->create(['slug' => 'extra-song-'.$position]);
            $item = ChurchServiceItem::factory()->song()->create([
                'church_service_id' => $service->id,
                'position' => $position,
                'title' => $song->title,
                'song_id' => $song->id,
            ]);
            ServiceSection::factory()->create([
                'media_processing_log_id' => $service->mediaProcessingLogs()->firstOrFail()->id,
                'church_service_item_id' => $item->id,
                'section_type' => ServiceSectionType::Song,
                'section_order' => $position,
                'song_match_type' => ServiceSectionSongMatchType::Confirmed,
                'publication_status' => ServiceSectionPublicationStatus::NotApplicable,
            ]);
        }

        $archive = app(PublicChurchServiceArchiveService::class);
        $fresh = ChurchService::findOrFail($service->id);

        DB::enableQueryLog();
        $archive->publicItems($fresh);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            8,
            $queries,
            'publicItems() must not issue a query per item; got '.$queries.'.',
        );
    }

    #[Test]
    public function public_items_never_leak_superseded_sections(): void
    {
        [$service, $sermon] = $this->processedService();

        $service->mediaProcessingLogs()->update(['superseded_at' => now()]);

        $items = app(PublicChurchServiceArchiveService::class)
            ->publicItems(ChurchService::findOrFail($service->id));

        // The sermon still resolves from the sermons table; the detected order does
        // not, because the run that produced it no longer represents the service.
        $this->assertTrue($items->pluck('title')->contains($sermon->title));
        $this->assertTrue(
            $items->where('kind', 'song')->every(fn (array $item): bool => $item['song_video_url'] === null),
            'A superseded run must not keep publishing its performance videos.',
        );
    }

    #[Test]
    public function public_service_history_is_disabled_with_service_tracking(): void
    {
        config(['service-tracking.enabled' => false]);

        $service = ChurchService::factory()->create([
            'date' => '2026-06-14',
            'service' => SermonService::Morning,
        ]);

        $this->get(route('church.services.index'))->assertNotFound();
        $this->get($this->showUrl($service))->assertNotFound();
    }

    private function showUrl(ChurchService $service): string
    {
        return route('church.services.show', [
            'date' => $service->date->format('Y-m-d'),
            'service' => $service->service->value,
        ]);
    }

    private function serviceWithScripture(string $date, SermonService $service, string $reading): ChurchService
    {
        $churchService = ChurchService::factory()->create([
            'date' => $date,
            'service' => $service,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'title' => $reading,
            'type' => 'bibles',
        ]);

        return $churchService;
    }

    /**
     * A service shaped the way the pipeline actually produces them.
     *
     * Crucially, no section carries a `published_sermon_id` and no sermon section
     * reaches `published`: no publication handler is registered for the sermon
     * section type, so that state is unreachable in production.
     *
     * @return array{0: ChurchService, 1: Sermon, 2: Sermon, 3: Song}
     */
    private function processedService(): array
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-07-26',
            'service' => SermonService::Morning,
        ]);

        $song = Song::factory()->create(['title' => 'Abide With Me', 'slug' => 'abide-with-me']);
        $closingSong = Song::factory()->create(['title' => 'Guide Me O Thou Great Jehovah', 'slug' => 'guide-me']);

        $sermon = Sermon::factory()->create([
            'title' => 'The Hope of the Gospel',
            'slug' => 'the-hope-of-the-gospel',
            'date' => $service->date,
            'service' => $service->service,
            'content_type' => SermonContentType::Sermon,
        ]);
        $childrensTalk = Sermon::factory()->create([
            'title' => 'God Is With Us',
            'slug' => 'god-is-with-us',
            'date' => $service->date,
            'service' => $service->service,
            'content_type' => SermonContentType::ChildrensTalk,
        ]);

        $songItem = ChurchServiceItem::factory()->song()->create([
            'church_service_id' => $service->id,
            'position' => 2,
            'title' => $song->title,
            'song_id' => $song->id,
        ]);
        $closingItem = ChurchServiceItem::factory()->song()->create([
            'church_service_id' => $service->id,
            'position' => 9,
            'title' => $closingSong->title,
            'song_id' => $closingSong->id,
        ]);
        $readingItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 5,
            'title' => 'Isaiah 53:1-6',
            'type' => 'bibles',
        ]);

        // Items that must never reach a public page.
        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'title' => 'Notices2024Looped.pptx',
            'type' => 'presentations',
        ]);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 3,
            'title' => 'scenechange:Lecturn',
            'type' => 'custom',
        ]);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 4,
            'title' => 'Prayer for the Hurst family',
            'type' => 'custom',
        ]);

        $run = MediaProcessingLog::factory()->create([
            'church_service_id' => $service->id,
            'processing_type' => MediaType::Livestream,
            'status' => ProcessingStatus::Completed,
            'superseded_at' => null,
        ]);

        $songSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $songItem->id,
            'section_type' => ServiceSectionType::Song,
            'section_order' => 1,
            'title' => $song->title,
            'song_match_type' => ServiceSectionSongMatchType::Confirmed,
            'publication_status' => ServiceSectionPublicationStatus::Published,
        ]);
        SongVideo::factory()->create([
            'song_id' => $song->id,
            'service_section_id' => $songSection->id,
            'church_service_id' => $service->id,
            'video_file_path' => 'sermons/songs/song-performance.mp4',
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::ChildrensTalk,
            'section_order' => 2,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable,
            'published_sermon_id' => null,
        ]);
        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $readingItem->id,
            'section_type' => ServiceSectionType::BibleReading,
            'section_order' => 3,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable,
        ]);
        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Sermon,
            'section_order' => 4,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable,
            'published_sermon_id' => null,
        ]);
        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $closingItem->id,
            'section_type' => ServiceSectionType::Song,
            'section_order' => 5,
            'title' => $closingSong->title,
            'song_match_type' => ServiceSectionSongMatchType::Confirmed,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable,
        ]);

        return [$service, $sermon, $childrensTalk, $song];
    }
}
