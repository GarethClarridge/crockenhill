<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SectionPublication;

use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Models\ServiceSection;
use App\Services\ChildrensTalkSpeakerService;
use App\Services\MediaProcessingIdentityResolver;
use App\Services\SectionPublication\SermonPublicationHandler;
use App\Services\SermonCreationService;
use App\Services\ServiceSectionPublicationTransitionService;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->childrensTalkSpeakerService = Mockery::mock(ChildrensTalkSpeakerService::class);
        $this->sermonCreationService = Mockery::mock(SermonCreationService::class);
        $this->identityResolver = Mockery::mock(MediaProcessingIdentityResolver::class);

        $this->handler = new SermonPublicationHandler(
            $this->childrensTalkSpeakerService,
            $this->sermonCreationService,
            $this->identityResolver,
            app(ServiceSectionPublicationTransitionService::class),
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
        $this->assertTrue($this->handler->requiresApproval());
    }

    #[Test]
    public function it_checks_reusable_media_requires_both_video_and_audio(): void
    {
        Storage::fake('local');

        $section = ServiceSection::factory()->create([
            'extracted_video_path' => null,
            'extracted_audio_path' => null,
            'extracted_at' => null,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
        ]);

        $this->assertFalse($this->handler->hasReusableExtractedMedia($section));
    }

    #[Test]
    public function it_checks_reusable_media_passes_when_both_paths_exist_on_disk(): void
    {
        Storage::fake('local');

        $videoPath = 'private/section-publications/1/video.mp4';
        $audioPath = 'private/section-publications/1/audio.mp3';

        Storage::disk('local')->put($videoPath, 'video-content');
        Storage::disk('local')->put($audioPath, 'audio-content');

        $section = ServiceSection::factory()->create([
            'extracted_video_path' => $videoPath,
            'extracted_audio_path' => $audioPath,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
        ]);

        $this->assertTrue($this->handler->hasReusableExtractedMedia($section));
    }

    #[Test]
    public function it_checks_eligibility_based_on_confidence_configuration(): void
    {
        config(['media-processing.section_publishing.require_high_confidence' => true]);

        $highConfidence = ServiceSection::factory()->create([
            'confidence' => 0.90,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
        ]);

        $lowConfidence = ServiceSection::factory()->create([
            'confidence' => 0.50,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
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
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
        ]);

        $this->assertTrue($this->handler->isEligible($lowConfidence));
    }

    #[Test]
    public function after_extraction_runs_speaker_detection_for_childrens_talks(): void
    {
        $section = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::CHILDRENS_TALK->value,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
        ]);

        $this->childrensTalkSpeakerService
            ->shouldReceive('detectAndStore')
            ->once()
            ->with($section);

        $this->handler->afterExtraction($section);
    }

    #[Test]
    public function after_extraction_is_noop_for_non_childrens_talk_sections(): void
    {
        $section = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::SERMON->value,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
        ]);

        $this->childrensTalkSpeakerService
            ->shouldNotReceive('detectAndStore');

        $this->handler->afterExtraction($section);
    }

    #[Test]
    public function on_section_removed_logs_warning_for_published_sections(): void
    {
        Log::spy();

        $section = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::CHILDRENS_TALK->value,
            'publication_status' => ServiceSectionPublicationStatus::PUBLISHED->value,
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
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
        ]);

        $this->handler->onSectionRemoved($section);

        Log::shouldNotHaveReceived('warning');
    }
}
