<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SermonPublicationState;
use App\Enums\SermonService;
use App\Models\HistoricImportOperation;
use App\Models\Sermon;
use App\Models\Song;
use App\Models\SongVideo;
use App\Models\User;
use App\Services\Import\HistoricSermonPublicationService;
use App\Services\Public\PodcastFeedService;
use App\Services\Public\SermonRepository;
use App\Services\Sermon\SermonStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HistoricSermonQuarantineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'church.services.public_from' => '2000-01-01',
            'media-processing.storage.historic_quarantine_disk' => 'historic_quarantine',
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.storage.transcript_disk' => 'public',
            'thumbnail-generation.storage.disk' => 'public',
            'podcast.cache.enabled' => false,
        ]);
        Storage::fake('historic_quarantine');
        Storage::fake('public');
        Cache::flush();
    }

    #[Test]
    public function quarantine_hides_whole_content_and_assets_from_every_public_entry_point(): void
    {
        $operation = $this->operation();
        $sermon = $this->quarantinedSermon($operation);
        $this->storePrivateAssets($sermon);

        $this->get(route('sermons.show', $sermon))->assertNotFound();
        $this->get(route('sermons.show.dated', [
            'year' => $sermon->date->format('Y'),
            'month' => $sermon->date->format('m'),
            'sermon' => $sermon,
        ]))->assertNotFound();
        $this->getJson(route('api.sermons.show', $sermon))->assertNotFound();

        foreach (['sermons.audio', 'sermons.video', 'sermons.thumbnail', 'sermons.transcript'] as $route) {
            $this->get(route($route, $sermon))->assertNotFound();
        }

        $this->assertFalse(app(SermonRepository::class)->publicSermonQuery()->whereKey($sermon)->exists());
        $this->assertFalse(Sermon::query()->whereVisibleInSitemap()->whereKey($sermon)->exists());
        $this->assertCount(
            0,
            app(PodcastFeedService::class)->getSermonsForFeed(SermonService::Morning),
        );
        $this->assertSame(
            route('sermons.audio', $sermon),
            app(SermonStorageService::class)->getAudioDeliveryUrl($sermon),
        );

        $admin = User::factory()->crockenhillAdmin()->create();
        $this->actingAs($admin)->get(route('sermons.audio', $sermon))
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    #[Test]
    public function separately_authorised_release_copies_verified_assets_and_preserves_provenance(): void
    {
        $operation = $this->operation();
        $sermon = $this->quarantinedSermon($operation);
        $this->storePrivateAssets($sermon);
        $processingId = $sermon->livestream_processing_id;

        $released = app(HistoricSermonPublicationService::class)->release($operation, $sermon);

        $this->assertSame(SermonPublicationState::Published, $released->publication_state);
        $this->assertSame('public', $released->asset_disk);
        $this->assertSame($processingId, $released->livestream_processing_id);
        $this->assertSame($operation->id, $released->historic_import_operation_id);
        Storage::disk('public')->assertExists((string) $released->audio_file_path);
        Storage::disk('public')->assertExists((string) $released->video_file_path);
        Storage::disk('public')->assertExists((string) $released->transcript_file_path);
        Storage::disk('public')->assertExists((string) $released->thumbnail_file_path);
        $this->assertDatabaseHas('historic_import_journal_entries', [
            'historic_import_operation_id' => $operation->id,
            'event' => 'sermon_released',
        ]);

        $this->get(route('sermons.show.dated', [
            'year' => $released->date->format('Y'),
            'month' => $released->date->format('m'),
            'sermon' => $released,
        ]))->assertOk();
        $this->getJson(route('api.sermons.show', $released))->assertOk();
        $this->get(route('sermons.audio', $released))->assertRedirect();
        $this->assertTrue(app(SermonRepository::class)->publicSermonQuery()->whereKey($released)->exists());
        $this->assertCount(
            1,
            app(PodcastFeedService::class)->getSermonsForFeed(SermonService::Morning),
        );
    }

    /**
     * A book listed here becomes a public filter entry and a sitemap URL, so a
     * quarantined era would announce itself through an otherwise-empty page.
     */
    #[Test]
    public function quarantined_scripture_references_do_not_reach_public_book_facets(): void
    {
        Sermon::factory()->create([
            'date' => '2026-02-01',
            'slug' => 'released-john-sermon',
            'reference' => 'John 3',
            'publication_state' => SermonPublicationState::Published,
        ]);
        Sermon::factory()->create([
            'date' => '2015-02-01',
            'slug' => 'quarantined-obadiah-sermon',
            'reference' => 'Obadiah 1',
            'publication_state' => SermonPublicationState::Quarantined,
            'historic_import_operation_id' => $this->operation()->id,
        ]);

        $books = app(SermonRepository::class)->getExistingScriptureBooks()->all();

        $this->assertContains('John', $books);
        $this->assertNotContains('Obadiah', $books);
        $this->assertSame(
            [3],
            app(SermonRepository::class)->getScriptureChapters('John')->all(),
        );
        $this->assertSame([], app(SermonRepository::class)->getScriptureChapters('Obadiah')->all());
    }

    #[Test]
    public function a_quarantined_song_recording_never_becomes_a_songs_public_video(): void
    {
        $song = Song::factory()->create();
        $released = SongVideo::factory()->create([
            'song_id' => $song->id,
            'recorded_date' => '2026-01-04',
            'publication_state' => SermonPublicationState::Published,
        ]);
        SongVideo::factory()->quarantined()->create([
            'song_id' => $song->id,
            // Deliberately later and featured: either would otherwise win.
            'recorded_date' => '2026-06-07',
            'is_featured' => true,
            'historic_import_operation_id' => $this->operation()->id,
        ]);

        $this->assertSame($released->id, $song->fresh()?->displayVideo()?->id);
    }

    private function operation(): HistoricImportOperation
    {
        return HistoricImportOperation::query()->firstOrCreate([
            // The identity contract's shape: the operation-owned asset prefix
            // and the production path guard both key off it.
            'operation_id' => 'historic-'.str_repeat('a', 32),
        ], [
            'binding_hash' => str_repeat('b', 64),
            'batch_key' => 'historic-sermon-quarantine',
            'manifest_hashes' => ['video' => str_repeat('c', 64)],
            'plan_hash' => str_repeat('d', 64),
            'target_fingerprint' => str_repeat('e', 64),
            'runtime_fingerprint' => str_repeat('f', 64),
            'notification_mode' => 'external_disabled',
            'max_cost_minor_units' => 100,
        ]);
    }

    private function quarantinedSermon(HistoricImportOperation $operation): Sermon
    {
        return Sermon::factory()->create([
            'date' => '2026-01-04',
            'service' => SermonService::Morning,
            'publication_state' => SermonPublicationState::Quarantined,
            'asset_disk' => 'historic_quarantine',
            'historic_import_operation_id' => $operation->id,
            'livestream_processing_id' => null,
            'audio_file_path' => 'sermons/1/audio.mp3',
            'video_file_path' => 'sermons/1/video.mp4',
            'transcript_file_path' => 'sermons/1/transcript.md',
            'thumbnail_file_path' => 'sermons/1/thumbnail.webp',
        ]);
    }

    private function storePrivateAssets(Sermon $sermon): void
    {
        foreach (['audio_file_path', 'video_file_path', 'transcript_file_path', 'thumbnail_file_path'] as $field) {
            Storage::disk('historic_quarantine')->put((string) $sermon->getAttribute($field), "{$field}-bytes");
        }
    }
}
