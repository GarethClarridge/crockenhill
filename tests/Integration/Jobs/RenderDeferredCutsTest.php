<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Actions\ExtractForCorpusRerun;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Exceptions\VideoProcessingException;
use App\Jobs\RenderDeferredCuts;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Models\SongVideo;
use App\Services\HistoricMedia\HistoricAssetPromotion;
use App\Services\Media\Video\VideoExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

/**
 * The render half of Tier C's "cut now, render later" (plan §4.0): every video a run owns is
 * re-encoded from its stored cut, and only then does the stamp say so.
 */
class RenderDeferredCutsTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    private const DISK = 'historic_quarantine';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(self::DISK);
    }

    #[Test]
    public function it_renders_every_video_the_run_owns_and_stamps_the_run_rendered(): void
    {
        $run = $this->deferredRun();
        $sermonVideo = $this->sermonVideo($run);
        $songVideo = $this->songVideo($run);
        $candidate = $this->heldCandidate($run);
        $this->sermonWithoutVideo($run);

        $rendered = [];
        $extractor = $this->createStub(VideoExtractionService::class);
        $extractor->method('renderForDelivery')
            ->willReturnCallback(function (string $path) use (&$rendered, $candidate): bool {
                $rendered[] = $path;

                // The held candidate's cut was already under the threshold.
                return $path !== Storage::disk(self::DISK)->path($candidate);
            });

        $this->handle($run, $extractor);

        $this->assertEqualsCanonicalizing(
            array_map(static fn (string $path): string => Storage::disk(self::DISK)->path($path), [$sermonVideo, $songVideo, $candidate]),
            $rendered,
        );

        $stamp = $this->latestStamp($run);
        $this->assertSame(ExtractForCorpusRerun::RENDER_RENDERED, $stamp['render']);
        $this->assertSame(['videos' => 3, 'rendered' => 2, 'already_deliverable' => 1], $stamp['render_counts']);
        $this->assertNotNull($stamp['rendered_at']);
        $this->assertFalse($run->refresh()->defersCorpusRerunRender());
    }

    #[Test]
    public function a_render_deferred_before_a_later_detection_round_is_still_rendered(): void
    {
        $run = $this->deferredRun();
        $run->putCorpusRerunStamp(['grounds' => 'corpus_rerun', 'media' => 'deferred']);
        $this->sermonVideo($run);

        $extractor = $this->createMock(VideoExtractionService::class);
        $extractor->expects($this->once())->method('renderForDelivery')->willReturn(true);

        $this->handle($run, $extractor);

        $this->assertFalse($run->refresh()->defersCorpusRerunRender());
    }

    #[Test]
    public function a_run_whose_render_is_not_deferred_is_left_alone(): void
    {
        $run = $this->deferredRun(render: ExtractForCorpusRerun::RENDER_RENDERED);
        $this->sermonVideo($run);

        $extractor = $this->createMock(VideoExtractionService::class);
        $extractor->expects($this->never())->method('renderForDelivery');

        $this->handle($run, $extractor);

        $this->assertArrayNotHasKey('rendered_at', $this->latestStamp($run));
    }

    /**
     * A detached drive reads like missing media. Every file is looked for before any is
     * rendered, so a missing one renders nothing and leaves the run deferred.
     */
    #[Test]
    public function a_missing_video_renders_nothing_and_leaves_the_run_deferred(): void
    {
        $run = $this->deferredRun();
        $this->sermonVideo($run);
        $songVideo = $this->songVideo($run);
        Storage::disk(self::DISK)->delete($songVideo);

        $extractor = $this->createMock(VideoExtractionService::class);
        $extractor->expects($this->never())->method('renderForDelivery');

        try {
            $this->handle($run, $extractor);
            $this->fail('A missing video must fail the render.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($songVideo, $exception->getMessage());
        }

        $this->assertTrue($run->refresh()->defersCorpusRerunRender());
    }

    #[Test]
    public function a_refused_render_leaves_the_run_deferred(): void
    {
        $run = $this->deferredRun();
        $this->sermonVideo($run);

        $extractor = $this->createStub(VideoExtractionService::class);
        $extractor->method('renderForDelivery')->willThrowException(new VideoProcessingException('Render refused: the frame count changed'));

        try {
            $this->handle($run, $extractor);
            $this->fail('A refused render must fail the job.');
        } catch (VideoProcessingException) {
        }

        $this->assertTrue($run->refresh()->defersCorpusRerunRender());
    }

    private function handle(MediaProcessingLog $run, VideoExtractionService $extractor): void
    {
        (new RenderDeferredCuts($run))->handle(app(HistoricAssetPromotion::class), $extractor);
    }

    private function deferredRun(string $render = ExtractForCorpusRerun::RENDER_DEFERRED): MediaProcessingLog
    {
        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'historic_import_operation_id' => $this->createHistoricImportOperation()->id,
        ]);
        $run->putCorpusRerunStamp([
            'media' => ExtractForCorpusRerun::MEDIA_EXTRACTED,
            'render' => $render,
        ]);

        return $run->refresh();
    }

    private function sermonVideo(MediaProcessingLog $run): string
    {
        $sermon = Sermon::factory()->create([
            'livestream_processing_id' => $run->processing_id,
            'asset_disk' => self::DISK,
        ]);
        $path = "sermons/video/{$sermon->id}.mp4";
        $sermon->forceFill(['video_file_path' => $path])->save();
        Storage::disk(self::DISK)->put($path, 'sermon-cut');

        return $path;
    }

    private function sermonWithoutVideo(MediaProcessingLog $run): void
    {
        Sermon::factory()->create([
            'livestream_processing_id' => $run->processing_id,
            'asset_disk' => self::DISK,
        ])->forceFill(['video_file_path' => null])->save();
    }

    private function songVideo(MediaProcessingLog $run): string
    {
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song,
            'publication_status' => ServiceSectionPublicationStatus::Published->value,
        ]);
        $songVideo = SongVideo::factory()->quarantined()->create(['service_section_id' => $section->id]);
        $path = "song-videos/{$songVideo->id}/song.mp4";
        $songVideo->forceFill(['video_file_path' => $path, 'asset_disk' => self::DISK])->save();
        // After publication the section names the song video's own file.
        $section->forceFill(['extracted_video_path' => $path, 'asset_disk' => self::DISK])->save();
        Storage::disk(self::DISK)->put($path, 'song-cut');

        return $path;
    }

    private function heldCandidate(MediaProcessingLog $run): string
    {
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::ShortTalk,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
        ]);
        $path = "section-publications/{$section->id}/talk.mp4";
        $section->forceFill(['extracted_video_path' => $path, 'asset_disk' => self::DISK])->save();
        Storage::disk(self::DISK)->put($path, 'talk-cut');

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function latestStamp(MediaProcessingLog $run): array
    {
        $stamps = $run->refresh()->corpusRerunStamps();

        return $stamps[count($stamps) - 1];
    }
}
