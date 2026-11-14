<?php

declare(strict_types=1);

namespace Tests\Integration\Services\HistoricMedia;

use App\Enums\AudioProfile;
use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SongVideo;
use App\Services\ChurchService\SectionPublication\SongPublicationHandler;
use App\Services\HistoricMedia\HistoricAssetPromotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\Support\PublicationCandidateFixture;
use Tests\TestCase;

/**
 * A song re-cut after its clip was promoted must reach quarantine (run 1306, 2026-11-14).
 *
 * `sections:recut` re-cut two published songs whose planned ends had moved, and the handler
 * republished them to their song paths on staging. Promotion then refused both, because the
 * quarantine copy from the earlier promotion differed and nothing told promotion the
 * replacement was intended: the only replacement authority was the run-wide one a sermon
 * re-extraction issues, and a clips-only recut stores no sermon.
 */
class RecutPublishedSongPromotionTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('historic_staging');
        Storage::fake('historic_quarantine');

        config()->set('media-processing.storage.historic_staging_disk', 'historic_staging');
        config()->set('media-processing.storage.historic_quarantine_disk', 'historic_quarantine');
        config()->set('media-processing.storage.temp_disk', 'local');
        config()->set('media-processing.storage.sermon_disk', 'historic_staging');
        config()->set('media-processing.storage.transcript_disk', 'historic_staging');
        config()->set('thumbnail-generation.storage.disk', 'historic_staging');
    }

    #[Test]
    public function a_recut_of_an_already_promoted_song_replaces_its_quarantine_copy(): void
    {
        [$log, $section, $songPath] = $this->promotedSong('the first cut');

        $this->recutAndRepublish($section, 'the re-cut with its end moved');

        $authority = $section->fresh()->metadata->raw['song_video_extraction']['promotion_replacement'];
        $this->assertSame($songPath, $authority['path']);
        $this->assertSame(strlen('the re-cut with its end moved'), $authority['size']);
        $this->assertSame(hash('sha256', 'the re-cut with its end moved'), $authority['sha256']);

        app(HistoricAssetPromotion::class)->promoteRun($log->fresh());

        $this->assertSame('the re-cut with its end moved', Storage::disk('historic_quarantine')->get($songPath));
        Storage::disk('historic_quarantine')->assertMissing($songPath.'.replacing');
        Storage::disk('historic_staging')->assertMissing($songPath);
        $songVideo = SongVideo::query()->where('service_section_id', $section->id)->sole();
        $this->assertSame('historic_quarantine', $songVideo->asset_disk);
        $this->assertSame('historic_quarantine', $section->fresh()->asset_disk);
    }

    #[Test]
    public function the_replacement_authority_is_spent_once_the_replacement_is_verified(): void
    {
        [$log, $section, $songPath] = $this->promotedSong('the first cut');
        $this->recutAndRepublish($section, 'the re-cut with its end moved');

        app(HistoricAssetPromotion::class)->promoteRun($log->fresh());

        $this->assertArrayNotHasKey(
            'promotion_replacement',
            $section->fresh()->metadata->raw['song_video_extraction'],
        );

        Storage::disk('historic_staging')->put($songPath, 'bytes nothing published');

        try {
            app(HistoricAssetPromotion::class)->promoteRun($log->fresh());
            $this->fail('A spent authority replaced a quarantine copy a second time.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('differs', $exception->getMessage());
        }

        $this->assertSame('the re-cut with its end moved', Storage::disk('historic_quarantine')->get($songPath));
    }

    /**
     * The authority names the bytes the handler published. Anything else at the song path is
     * the same-path conflict promotion exists to refuse, even on a section that was re-published.
     */
    #[Test]
    public function staging_bytes_other_than_the_republished_cut_are_still_refused(): void
    {
        [$log, $section, $songPath] = $this->promotedSong('the first cut');
        $this->recutAndRepublish($section, 'the re-cut with its end moved');
        Storage::disk('historic_staging')->put($songPath, 'the re-cut, rewritten after');

        try {
            app(HistoricAssetPromotion::class)->promoteRun($log->fresh());
            $this->fail('Promotion replaced quarantine with bytes the authority does not name.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('differs', $exception->getMessage());
        }

        $this->assertSame('the first cut', Storage::disk('historic_quarantine')->get($songPath));
        Storage::disk('historic_staging')->assertExists($songPath);
    }

    /** A first publication replaces nothing, so it records no authority to replace anything. */
    #[Test]
    public function a_first_publication_records_no_replacement_authority(): void
    {
        [, $section] = $this->promotedSong('the first cut');

        $this->assertArrayNotHasKey(
            'promotion_replacement',
            $section->fresh()->metadata->raw['song_video_extraction'],
        );
    }

    /**
     * A historic run whose song was published and promoted once.
     *
     * @return array{0: MediaProcessingLog, 1: ServiceSection, 2: string}
     */
    private function promotedSong(string $firstCut): array
    {
        $operation = $this->createHistoricImportOperation();
        $churchService = ChurchService::factory()->create(['date' => '2021-09-26']);
        $song = Song::factory()->create();
        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'song_id' => $song->id,
        ]);
        $log = MediaProcessingLog::factory()->create([
            'processing_type' => MediaType::Livestream,
            'status' => ProcessingStatus::Processing,
            'historic_import_operation_id' => $operation->id,
            'church_service_id' => $churchService->id,
        ]);
        $candidatePath = 'section-publications/candidate/video.mp4';
        Storage::disk('historic_staging')->put($candidatePath, $firstCut);
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Confirmed->value,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
            'asset_disk' => 'historic_staging',
            'extracted_video_path' => $candidatePath,
            'extracted_at' => now(),
            'start_time' => 60.0,
            'end_time' => 240.0,
            'metadata' => ['publication_candidate_extraction' => PublicationCandidateFixture::current(AudioProfile::Music)],
        ]);

        app(SongPublicationHandler::class)->publish($section->fresh());
        app(HistoricAssetPromotion::class)->promoteRun($log->fresh());

        $songPath = 'sermons/songs/'.$song->id.'/'.$section->id.'.mp4';
        $this->assertSame($firstCut, Storage::disk('historic_quarantine')->get($songPath));
        Storage::disk('historic_staging')->assertMissing($songPath);

        return [$log, $section->fresh(), $songPath];
    }

    /** What `sections:recut` does to a published song whose cut changed: a new candidate, published in place. */
    private function recutAndRepublish(ServiceSection $section, string $recut): void
    {
        $candidatePath = 'section-publications/candidate/video.mp4';
        Storage::disk('historic_staging')->put($candidatePath, $recut);
        $section->refresh();
        $section->update([
            'asset_disk' => 'historic_staging',
            'extracted_video_path' => $candidatePath,
            'metadata' => [
                ...($section->metadata?->toArray() ?? []),
                'publication_candidate_extraction' => PublicationCandidateFixture::current(AudioProfile::Music),
            ],
        ]);

        app(SongPublicationHandler::class)->publish($section->fresh());
    }
}
