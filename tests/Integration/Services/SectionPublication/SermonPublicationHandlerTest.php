<?php

declare(strict_types=1);

namespace Tests\Integration\Services\SectionPublication;

use App\Data\ServiceSectionMetadata;
use App\Enums\SermonService;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Services\ChurchService\ExtractedSectionMediaChecker;
use App\Services\ChurchService\SectionPublication\ChildrensTalkBoundaryEvidenceService;
use App\Services\ChurchService\SectionPublication\SermonPublicationHandler;
use App\Services\ChurchService\ServiceSectionPublicationTransitionService;
use App\Services\Preacher\ChildrensTalkSpeakerService;
use App\Services\Processing\MediaProcessingIdentityResolver;
use App\Services\Sermon\SermonCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonPublicationHandlerTest extends TestCase
{
    use RefreshDatabase;

    private SermonPublicationHandler $handler;

    private MockInterface $childrensTalkSpeakerService;

    private MockInterface $sermonCreationService;

    private MockInterface $identityResolver;

    private MockInterface $publicationTransitions;

    private MockInterface $childrensTalkBoundaryEvidence;

    protected function setUp(): void
    {
        parent::setUp();

        $this->childrensTalkSpeakerService = Mockery::mock(ChildrensTalkSpeakerService::class);
        $this->sermonCreationService = Mockery::mock(SermonCreationService::class);
        $this->identityResolver = Mockery::mock(MediaProcessingIdentityResolver::class);
        $this->publicationTransitions = Mockery::mock(ServiceSectionPublicationTransitionService::class);
        $this->childrensTalkBoundaryEvidence = Mockery::mock(ChildrensTalkBoundaryEvidenceService::class);

        $this->handler = new SermonPublicationHandler(
            $this->childrensTalkSpeakerService,
            $this->sermonCreationService,
            $this->identityResolver,
            $this->publicationTransitions,
            app(ExtractedSectionMediaChecker::class),
            $this->childrensTalkBoundaryEvidence,
        );
    }

    #[Test]
    public function it_requires_audio_extraction(): void
    {
        $this->assertTrue($this->handler->requiresAudioExtraction());
    }

    #[Test]
    public function it_requires_approval(): void
    {
        $this->assertTrue($this->handler->requiresApproval(ServiceSection::factory()->make()));
    }

    #[Test]
    public function it_checks_reusable_media_requires_both_video_and_audio(): void
    {
        Storage::fake('public');

        $section = ServiceSection::factory()->create([
            'extracted_video_path' => null,
            'extracted_audio_path' => null,
            'extracted_at' => null,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        $this->assertFalse($this->handler->hasReusableExtractedMedia($section));
    }

    #[Test]
    public function it_checks_reusable_media_passes_when_both_paths_exist_on_disk(): void
    {
        Storage::fake('public');

        $videoPath = 'section-publications/1-abcdef0123456789/video.mp4';
        $audioPath = 'section-publications/1-abcdef0123456789/audio.mp3';

        Storage::disk('public')->put($videoPath, 'video-content');
        Storage::disk('public')->put($audioPath, 'audio-content');

        $section = ServiceSection::factory()->create([
            'extracted_video_path' => $videoPath,
            'extracted_audio_path' => $audioPath,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        $this->assertTrue($this->handler->hasReusableExtractedMedia($section));
    }

    #[Test]
    public function it_checks_eligibility_based_on_confidence_configuration(): void
    {
        config(['media-processing.section_publishing.require_high_confidence' => true]);

        $highConfidence = ServiceSection::factory()->create([
            'confidence' => 0.90,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        $lowConfidence = ServiceSection::factory()->create([
            'confidence' => 0.50,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        $this->assertTrue($this->handler->isEligible($highConfidence));
        $this->assertFalse($this->handler->isEligible($lowConfidence));
    }

    #[Test]
    public function it_is_eligible_regardless_of_confidence_when_high_confidence_not_required(): void
    {
        config(['media-processing.section_publishing.require_high_confidence' => false]);

        $lowConfidence = ServiceSection::factory()->create([
            'confidence' => 0.50,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        $this->assertTrue($this->handler->isEligible($lowConfidence));
    }

    #[Test]
    public function after_extraction_runs_speaker_detection_for_childrens_talks(): void
    {
        $section = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        $this->childrensTalkSpeakerService
            ->shouldReceive('detectAndStore')
            ->once()
            ->with($section);
        $this->childrensTalkBoundaryEvidence
            ->shouldReceive('assess')
            ->once()
            ->with($section)
            ->andReturn([
                'candidate' => [
                    'kind' => 'inclusive',
                    'start_time' => (float) $section->start_time,
                    'end_time' => (float) $section->end_time,
                ],
            ]);

        $this->handler->afterExtraction($section);

        $this->assertSame(
            'inclusive',
            $section->metadata?->toArray()['childrens_talk_boundary']['candidate']['kind'] ?? null,
        );
    }

    #[Test]
    public function after_extraction_is_noop_for_non_childrens_talk_sections(): void
    {
        $section = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::Sermon->value,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        $this->childrensTalkSpeakerService
            ->shouldNotReceive('detectAndStore');
        $this->childrensTalkBoundaryEvidence
            ->shouldNotReceive('assess');

        $this->handler->afterExtraction($section);
    }

    #[Test]
    public function on_section_removed_logs_warning_for_published_sections(): void
    {
        Log::spy();

        $section = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'publication_status' => ServiceSectionPublicationStatus::Published->value,
        ]);

        $this->handler->onSectionRemoved($section);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'Published service section removed'));
    }

    #[Test]
    public function on_section_removed_does_nothing_for_unpublished_sections(): void
    {
        Log::spy();

        $section = ServiceSection::factory()->create([
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        $this->handler->onSectionRemoved($section);

        Log::shouldNotHaveReceived('warning');
    }

    // --- publish() ---

    #[Test]
    public function publish_successfully_promotes_section_to_sermon(): void
    {
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $videoPath = 'section-publications/1-abcdef0123456789/video.mp4';
        $audioPath = 'section-publications/1-abcdef0123456789/audio.mp3';
        Storage::disk('public')->put($videoPath, 'video-content');
        Storage::disk('public')->put($audioPath, 'audio-content');

        $processingLog = MediaProcessingLog::factory()->audio()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'extracted_video_path' => $videoPath,
            'extracted_audio_path' => $audioPath,
            'publication_status' => ServiceSectionPublicationStatus::Approved->value,
        ]);

        $signature = $section->classificationSignature();
        $section->metadata = ServiceSectionMetadata::fromArray([
            'publication' => ['approved_signature' => $signature],
        ]);
        $section->save();

        $this->identityResolver->shouldReceive('resolve')
            ->once()
            ->with(Mockery::on(fn ($arg) => $arg->id === $processingLog->id))
            ->andReturn(['date' => now()->toDateString(), 'service' => SermonService::Morning]);

        $this->publicationTransitions->shouldReceive('transition')
            ->once()
            ->with($section, ServiceSectionPublicationStatus::Published)
            ->andReturnUsing(function ($section, $status) {
                $section->publication_status = $status;

                return true;
            });

        $sermon = Sermon::factory()->create();
        $this->sermonCreationService->shouldReceive('createSermon')
            ->once()
            ->andReturn($sermon);

        $this->handler->publish($section);

        $section->refresh();
        $this->assertEquals($sermon->id, $section->published_sermon_id);
        $this->assertNotNull($section->published_at);
        $this->assertNull($section->unpublished_expires_at);

        // Assets should be promoted
        Storage::disk('public')->assertExists($section->extracted_video_path);
        Storage::disk('public')->assertExists($section->extracted_audio_path);
    }

    #[Test]
    public function publish_throws_exception_when_video_path_is_missing(): void
    {
        $section = ServiceSection::factory()->create(['extracted_video_path' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Section video path missing');

        $this->handler->publish($section);
    }

    #[Test]
    public function publish_throws_exception_when_audio_path_is_missing(): void
    {
        $section = ServiceSection::factory()->create([
            'extracted_video_path' => 'video.mp4',
            'extracted_audio_path' => null,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Section audio path missing');

        $this->handler->publish($section);
    }

    #[Test]
    public function publish_throws_exception_when_video_file_is_missing_on_disk(): void
    {
        Storage::fake('public');
        $processingLog = MediaProcessingLog::factory()->audio()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'extracted_video_path' => 'section-publications/1-abcdef0123456789/missing.mp4',
            'extracted_audio_path' => 'section-publications/1-abcdef0123456789/exists.mp3',
        ]);

        $this->identityResolver->shouldReceive('resolve')
            ->byDefault()
            ->with(Mockery::on(fn ($arg) => $arg->id === $processingLog->id))
            ->andReturn(['date' => now()->toDateString(), 'service' => SermonService::Morning]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Section video file is missing');

        $this->handler->publish($section);
    }

    #[Test]
    public function publish_throws_exception_when_audio_file_is_missing_on_disk(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('section-publications/1-abcdef0123456789/video.mp4', 'content');

        $processingLog = MediaProcessingLog::factory()->audio()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'extracted_video_path' => 'section-publications/1-abcdef0123456789/video.mp4',
            'extracted_audio_path' => 'section-publications/1-abcdef0123456789/missing.mp3',
        ]);

        $this->identityResolver->shouldReceive('resolve')
            ->byDefault()
            ->with(Mockery::on(fn ($arg) => $arg->id === $processingLog->id))
            ->andReturn(['date' => now()->toDateString(), 'service' => SermonService::Morning]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Section audio file is missing');

        $this->handler->publish($section);
    }

    #[Test]
    public function publish_throws_exception_when_identity_resolution_fails(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('section-publications/1-abcdef0123456789/video.mp4', 'content');
        Storage::disk('public')->put('section-publications/1-abcdef0123456789/audio.mp3', 'content');

        $processingLog = MediaProcessingLog::factory()->audio()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'extracted_video_path' => 'section-publications/1-abcdef0123456789/video.mp4',
            'extracted_audio_path' => 'section-publications/1-abcdef0123456789/audio.mp3',
        ]);

        $this->identityResolver->shouldReceive('resolve')
            ->once()
            ->with(Mockery::on(fn ($arg) => $arg->id === $processingLog->id))
            ->andReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to resolve processing identity');

        $this->handler->publish($section);
    }

    #[Test]
    public function publish_throws_exception_when_approval_signature_is_missing(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('section-publications/1-abcdef0123456789/video.mp4', 'content');
        Storage::disk('public')->put('section-publications/1-abcdef0123456789/audio.mp3', 'content');

        $processingLog = MediaProcessingLog::factory()->audio()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'extracted_video_path' => 'section-publications/1-abcdef0123456789/video.mp4',
            'extracted_audio_path' => 'section-publications/1-abcdef0123456789/audio.mp3',
            'metadata' => null,
        ]);

        $this->identityResolver->shouldReceive('resolve')
            ->once()
            ->with(Mockery::on(fn ($arg) => $arg->id === $processingLog->id))
            ->andReturn(['date' => now()->toDateString(), 'service' => SermonService::Morning]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Section approval signature is missing');

        $this->handler->publish($section);
    }

    #[Test]
    public function publish_throws_exception_when_signature_mismatches(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('section-publications/1-abcdef0123456789/video.mp4', 'content');
        Storage::disk('public')->put('section-publications/1-abcdef0123456789/audio.mp3', 'content');

        $processingLog = MediaProcessingLog::factory()->audio()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'extracted_video_path' => 'section-publications/1-abcdef0123456789/video.mp4',
            'extracted_audio_path' => 'section-publications/1-abcdef0123456789/audio.mp3',
            'title' => 'Original Title',
        ]);

        $section->metadata = ServiceSectionMetadata::fromArray([
            'publication' => ['approved_signature' => $section->classificationSignature()],
        ]);
        $section->save();

        // Change title to invalidate signature
        $section->title = 'Modified Title';

        $this->identityResolver->shouldReceive('resolve')
            ->once()
            ->with(Mockery::on(fn ($arg) => $arg->id === $processingLog->id))
            ->andReturn(['date' => now()->toDateString(), 'service' => SermonService::Morning]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Section classification changed since approval');

        $this->handler->publish($section);
    }

    #[Test]
    public function publish_throws_exception_for_childrens_talk_with_unresolved_speaker(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('section-publications/1-abcdef0123456789/video.mp4', 'content');
        Storage::disk('public')->put('section-publications/1-abcdef0123456789/audio.mp3', 'content');

        $processingLog = MediaProcessingLog::factory()->audio()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'extracted_video_path' => 'section-publications/1-abcdef0123456789/video.mp4',
            'extracted_audio_path' => 'section-publications/1-abcdef0123456789/audio.mp3',
        ]);

        $section->metadata = ServiceSectionMetadata::fromArray([
            'publication' => ['approved_signature' => $section->classificationSignature()],
            'childrens_talk_speaker' => null, // Unresolved
        ]);
        $section->save();

        $this->identityResolver->shouldReceive('resolve')
            ->once()
            ->with(Mockery::on(fn ($arg) => $arg->id === $processingLog->id))
            ->andReturn(['date' => now()->toDateString(), 'service' => SermonService::Morning]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Children's talk speaker must be reviewed");

        $this->handler->publish($section);
    }

    #[Test]
    public function publish_throws_exception_when_transition_to_published_fails(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('section-publications/1-abcdef0123456789/video.mp4', 'content');
        Storage::disk('public')->put('section-publications/1-abcdef0123456789/audio.mp3', 'content');

        $processingLog = MediaProcessingLog::factory()->audio()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'extracted_video_path' => 'section-publications/1-abcdef0123456789/video.mp4',
            'extracted_audio_path' => 'section-publications/1-abcdef0123456789/audio.mp3',
        ]);

        $section->metadata = ServiceSectionMetadata::fromArray([
            'publication' => ['approved_signature' => $section->classificationSignature()],
        ]);
        $section->save();

        $this->identityResolver->shouldReceive('resolve')
            ->once()
            ->with(Mockery::on(fn ($arg) => $arg->id === $processingLog->id))
            ->andReturn(['date' => now()->toDateString(), 'service' => SermonService::Morning]);

        $this->publicationTransitions->shouldReceive('transition')
            ->andReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid state transition when publishing');

        $this->handler->publish($section);
    }
}
