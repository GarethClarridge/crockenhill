<?php

declare(strict_types=1);

namespace Tests\Feature\Actions\ServiceReview;

use App\Actions\ServiceReview\SaveServiceSection;
use App\Enums\SermonService;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\ServiceSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SaveServiceSectionTest extends TestCase
{
    use RefreshDatabase;

    private SaveServiceSection $action;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(SaveServiceSection::class);
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($this->admin);
    }

    #[Test]
    public function it_persists_corrected_section_type_and_title(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-06-01',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::SONG->value,
            'title' => 'Misc item',
            'needs_manual_review' => true,
            'metadata' => ['review_reason' => 'oos_structure_mismatch'],
        ]);

        $this->action->execute(
            section: $section,
            sectionEdits: [$section->id => ['section_type' => ServiceSectionType::PRAYER->value, 'title' => 'Pastoral Prayer']],
            speakerEdits: [],
            userId: $this->admin->id,
        );

        $section->refresh();
        $metadata = $section->metadata?->toArray() ?? [];

        $this->assertSame(ServiceSectionType::PRAYER, $section->section_type);
        $this->assertSame('Pastoral Prayer', $section->title);
        $this->assertFalse($section->needs_manual_review);
        $this->assertArrayNotHasKey('review_reason', $metadata);
        $this->assertArrayHasKey('manual_review', $metadata);
    }

    #[Test]
    public function it_throws_validation_exception_for_invalid_section_type(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'needs_manual_review' => true,
        ]);

        $this->expectException(ValidationException::class);

        $this->action->execute(
            section: $section,
            sectionEdits: [$section->id => ['section_type' => 'invalid_type', 'title' => 'Test']],
            speakerEdits: [],
            userId: $this->admin->id,
        );
    }

    #[Test]
    public function it_throws_validation_exception_for_empty_title(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'needs_manual_review' => true,
        ]);

        $this->expectException(ValidationException::class);

        $this->action->execute(
            section: $section,
            sectionEdits: [$section->id => ['section_type' => ServiceSectionType::PRAYER->value, 'title' => '']],
            speakerEdits: [],
            userId: $this->admin->id,
        );
    }

    #[Test]
    public function it_transitions_non_publishable_section_to_not_applicable(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::SONG->value,
            'needs_manual_review' => true,
            'publication_status' => ServiceSectionPublicationStatus::PENDING_APPROVAL->value,
        ]);

        $this->action->execute(
            section: $section,
            sectionEdits: [$section->id => ['section_type' => ServiceSectionType::PRAYER->value, 'title' => 'Prayer']],
            speakerEdits: [],
            userId: $this->admin->id,
        );

        $section->refresh();

        $this->assertSame(ServiceSectionPublicationStatus::NOT_APPLICABLE, $section->publication_status);
    }

    #[Test]
    public function it_transitions_to_pending_approval_when_publishable_and_media_exists(): void
    {
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $run = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::CHILDRENS_TALK->value,
            'title' => "Children's Talk",
            'needs_manual_review' => true,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
            'extracted_video_path' => 'sections/video.mp4',
            'extracted_audio_path' => 'sections/audio.mp3',
            'metadata' => [
                'review_reason' => 'childrens_talk_speaker_ambiguous',
                'childrens_talk_speaker' => [
                    'predicted' => ['outcome' => 'ambiguous', 'preacher_name' => 'Someone'],
                ],
            ],
        ]);

        Storage::disk('public')->put('sections/video.mp4', 'video');
        Storage::disk('public')->put('sections/audio.mp3', 'audio');

        $preacher = Preacher::factory()->create();

        $this->action->execute(
            section: $section,
            sectionEdits: [$section->id => ['section_type' => ServiceSectionType::CHILDRENS_TALK->value, 'title' => "Children's Talk"]],
            speakerEdits: [$section->id => ['preacher_id' => (string) $preacher->id, 'speaker_name' => '']],
            userId: $this->admin->id,
        );

        $section->refresh();

        $this->assertFalse($section->needs_manual_review);
        $this->assertSame(ServiceSectionPublicationStatus::PENDING_APPROVAL, $section->publication_status);
    }

    #[Test]
    public function it_dispatches_prepare_candidates_when_publishable_but_no_media_extracted(): void
    {
        Bus::fake();

        $run = MediaProcessingLog::factory()->livestream()->create();

        $preacher = Preacher::factory()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::CHILDRENS_TALK->value,
            'title' => "Children's Talk",
            'needs_manual_review' => true,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
            'extracted_video_path' => null,
            'extracted_audio_path' => null,
            'metadata' => [
                'confidence_level' => 'high',
                'review_reason' => 'demoted_secondary_sermon_to_childrens_talk',
                'review_flags' => ['heuristic_demotion'],
            ],
        ]);

        $this->action->execute(
            section: $section,
            sectionEdits: [$section->id => ['section_type' => ServiceSectionType::CHILDRENS_TALK->value, 'title' => "Children's Talk"]],
            speakerEdits: [$section->id => ['preacher_id' => (string) $preacher->id, 'speaker_name' => '']],
            userId: $this->admin->id,
        );

        $section->refresh();

        $this->assertFalse($section->needs_manual_review);
        $this->assertSame(ServiceSectionPublicationStatus::NOT_APPLICABLE, $section->publication_status);
        Bus::assertDispatched(PrepareSectionPublicationCandidates::class);
    }

    #[Test]
    public function it_requires_speaker_for_childrens_talk_with_no_existing_speaker(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::CHILDRENS_TALK->value,
            'needs_manual_review' => true,
        ]);

        $this->expectException(ValidationException::class);

        $this->action->execute(
            section: $section,
            sectionEdits: [$section->id => ['section_type' => ServiceSectionType::CHILDRENS_TALK->value, 'title' => "Children's Talk"]],
            speakerEdits: [$section->id => ['preacher_id' => '', 'speaker_name' => '']],
            userId: $this->admin->id,
        );
    }

    #[Test]
    public function it_uses_section_defaults_when_no_edits_provided_for_section(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::WELCOME->value,
            'title' => 'Welcome',
            'needs_manual_review' => true,
        ]);

        $this->action->execute(
            section: $section,
            sectionEdits: [],
            speakerEdits: [],
            userId: $this->admin->id,
        );

        $section->refresh();

        $this->assertSame(ServiceSectionType::WELCOME, $section->section_type);
        $this->assertSame('Welcome', $section->title);
    }

    #[Test]
    public function it_persists_even_when_section_is_no_longer_a_review_candidate(): void
    {
        // The component guards against this with isReviewCandidate() before calling the action.
        // The action itself does not re-check — it always persists whatever is passed.
        // This test documents that contract so the gatekeeper responsibility is explicit.
        $run = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::WELCOME->value,
            'title' => 'Old Title',
            'needs_manual_review' => false,
            'confidence' => 0.99,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
        ]);

        $this->action->execute(
            section: $section,
            sectionEdits: [$section->id => ['section_type' => ServiceSectionType::PRAYER->value, 'title' => 'Updated Title']],
            speakerEdits: [],
            userId: $this->admin->id,
        );

        $section->refresh();

        $this->assertSame(ServiceSectionType::PRAYER, $section->section_type);
        $this->assertSame('Updated Title', $section->title);
    }
}
