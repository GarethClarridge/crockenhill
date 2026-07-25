<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Actions\Publication\ApproveSectionForPublication;
use App\Contracts\SpeakerIdentificationInterface;
use App\Data\SpeakerMatchResult;
use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Jobs\PublishApprovedServiceSection;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Models\SpeakerProfile;
use App\Services\ChurchService\SectionPublication\SectionPublicationHandlerFactory;
use App\Services\ChurchService\SectionPublication\SermonPublicationHandler;
use App\Services\ChurchService\ServiceSectionPublicationTransitionService;
use App\Services\Media\Video\VideoExtractionService;
use App\Services\Processing\StorageAdapterHelper;
use App\Services\Sermon\SermonExposurePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * F2: end-to-end children's-talk publication workflow.
 *
 * The nine-scenario regression harness stops after ExtractSermon, so the
 * prepare -> approve -> publish chain was never exercised together. This drives all
 * three real steps against a detected children's-talk section, mocking only the
 * external/heavy pieces (ffmpeg extraction and the speaker-identification provider),
 * and asserts a published Sermon with the children's-talk content type results.
 */
class ChildrensTalkPublicationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_prepares_approves_and_publishes_a_childrens_talk_section_end_to_end(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        config([
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.section_publishing.enabled' => true,
            'media-processing.section_publishing.handlers' => ['childrens_talk' => SermonPublicationHandler::class],
            'media-processing.section_publishing.retain_unpublished_hours' => 48,
            'media-processing.speaker_identification.enabled' => true,
        ]);

        // Defer the dispatched publish job so we can run it deterministically below.
        Bus::fake([PublishApprovedServiceSection::class]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create([
            'source_file_path' => 'livestreams/source.mp4',
            'extracted_date' => '2026-05-31',
            'extracted_service' => SermonService::Morning->value,
            'original_filename' => '2026-05-31-morning-service.mp4',
        ]);

        Storage::disk('local')->put('livestreams/source.mp4', 'source-video');

        $preacher = Preacher::factory()->create(['name' => 'Mary Helper']);
        $profile = SpeakerProfile::factory()->create(['preacher_id' => $preacher->id, 'is_active' => true]);

        // The speaker-identification provider is the only AI dependency; mock it to a match
        // so the children's-talk speaker resolves automatically (no manual review needed).
        $speakerService = $this->createStub(SpeakerIdentificationInterface::class);
        $speakerService->method('identify')->willReturn(SpeakerMatchResult::matched(
            $profile->load('preacher'),
            0.93,
            0.61,
            [$profile->id => 0.93],
        ));
        $this->instance(SpeakerIdentificationInterface::class, $speakerService);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'status' => ServiceSectionStatus::Identified->value,
            'needs_manual_review' => false,
            'confidence' => 0.92,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'ai_transcript'],
            'title' => "Children's Talk",
            'start_time' => 300.0,
            'end_time' => 780.0,
        ]);

        // ── Step 1: prepare candidates (extract media, detect speaker, request approval) ──
        $job = new PrepareSectionPublicationCandidates($processingLog);
        $job->handle(
            $this->fakeVideoExtractor(),
            app(StorageAdapterHelper::class),
            app(SectionPublicationHandlerFactory::class),
            app(ServiceSectionPublicationTransitionService::class),
        );

        $section->refresh();
        $this->assertSame(ServiceSectionPublicationStatus::PendingApproval, $section->publication_status);
        $this->assertFalse($section->needs_manual_review, 'A matched speaker should clear manual review.');
        $this->assertTrue($section->hasResolvedChildrensTalkSpeaker());

        // ── Step 2: approve for publication ──────────────────────────────────────────────
        $approvalError = app(ApproveSectionForPublication::class)->execute($section);

        $this->assertNull($approvalError, 'Approval should succeed once media and speaker are resolved.');
        $section->refresh();
        $this->assertSame(ServiceSectionPublicationStatus::Approved, $section->publication_status);
        Bus::assertDispatched(PublishApprovedServiceSection::class);

        // ── Step 3: publish the approved section ─────────────────────────────────────────
        (new PublishApprovedServiceSection($section->id))->handle(
            app(SectionPublicationHandlerFactory::class),
        );

        $section->refresh();
        $sermon = Sermon::query()->findOrFail($section->published_sermon_id);

        $this->assertSame(ServiceSectionPublicationStatus::Published, $section->publication_status);
        $this->assertNotNull($section->published_at);
        $this->assertNull($section->unpublished_expires_at);
        $this->assertSame(SermonContentType::ChildrensTalk, $sermon->content_type);
        $this->assertSame($preacher->id, $sermon->preacher_id);
        // Media stays on the ordinary sermon disk under an ordinary sermon key.
        // It used to be relocated to the local `private/` disk, which production
        // never persisted, so every published talk's video died at the next deploy.
        $this->assertSame('sermons/sections/'.$section->id.'/video.mp4', $sermon->video_file_path);
        Storage::disk('public')->assertExists('sermons/sections/'.$section->id.'/video.mp4');
        Storage::disk('local')->assertMissing('private/sermons/sections/'.$section->id.'/video.mp4');

        // Storage moved; the gate did not. Discovery and guest access are still
        // closed, driven by `CHILDRENS_TALKS_PUBLIC` rather than by any file path.
        $exposurePolicy = app(SermonExposurePolicy::class);
        $this->assertFalse($exposurePolicy->canAccessChildrensCorner(null));
        $this->assertFalse($exposurePolicy->shouldExposeOnSermonApi($sermon));
        $this->assertFalse($exposurePolicy->shouldIncludeInSitemap($sermon));
    }

    /**
     * A stand-in for the ffmpeg-backed extractor: it writes placeholder assets to the temp
     * disk and returns the paths the real service would, so the candidate media genuinely
     * exists for the later approval and publish media checks.
     */
    private function fakeVideoExtractor(): VideoExtractionService
    {
        $videoExtractor = $this->createStub(VideoExtractionService::class);

        $videoExtractor->method('extractSegmentAsFile')
            ->willReturnCallback(function (string $inputPath, object $segment, ?string $outputFilename): string {
                $path = 'temp/'.($outputFilename ?? 'section.mp4');
                Storage::disk('local')->put($path, 'section-video');

                return $path;
            });

        $videoExtractor->method('extractOptimizedAudio')
            ->willReturnCallback(function (string $inputPath, object $segment, string $filename, string $disk, string $directory): array {
                $audioPath = $directory.'/'.$filename;
                Storage::disk('local')->put($audioPath, 'section-audio');

                return [
                    'audio_path' => $audioPath,
                    'full_path' => Storage::disk('local')->path($audioPath),
                    'original_size' => 1024,
                    'final_size' => 1024,
                    'compression_applied' => false,
                    'compression_ratio' => 1.0,
                    'valid_for_transcription' => true,
                ];
            });

        return $videoExtractor;
    }
}
