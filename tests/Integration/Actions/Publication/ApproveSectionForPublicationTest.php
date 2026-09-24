<?php

declare(strict_types=1);

namespace Tests\Integration\Actions\Publication;

use App\Actions\Publication\ApproveSectionForPublication;
use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Jobs\PublishApprovedServiceSection;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\SectionPublication\TalkPublicationHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApproveSectionForPublicationTest extends TestCase
{
    use RefreshDatabase;

    private ApproveSectionForPublication $action;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $this->action = app(ApproveSectionForPublication::class);
    }

    private function makePendingSection(array $attributes = []): ServiceSection
    {
        $run = MediaProcessingLog::factory()->livestream()->create();

        return ServiceSection::factory()->create(array_merge([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Welcome->value,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
            'extracted_video_path' => 'sermons/sections/1/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-1.mp3',
        ], $attributes));
    }

    // -------------------------------------------------------------------------
    // approvalBlocker / hasExtractedMedia
    // -------------------------------------------------------------------------

    #[Test]
    public function it_returns_error_when_extracted_paths_are_empty(): void
    {
        $section = $this->makePendingSection([
            'extracted_video_path' => null,
            'extracted_audio_path' => null,
        ]);

        $result = $this->action->execute($section);

        $this->assertSame('Section media is missing. Reclassify and prepare candidates again.', $result);
    }

    #[Test]
    public function it_returns_error_when_media_files_do_not_exist_on_disk(): void
    {
        $section = $this->makePendingSection([
            'extracted_video_path' => 'sermons/sections/99/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-99.mp3',
        ]);
        // Files are NOT put on the fake disk — they're missing.

        $result = $this->action->execute($section);

        $this->assertSame('Section media is missing. Reclassify and prepare candidates again.', $result);
    }

    #[Test]
    public function it_returns_error_when_only_video_is_missing_on_disk(): void
    {
        $section = $this->makePendingSection([
            'extracted_video_path' => 'sermons/sections/2/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-2.mp3',
        ]);

        Storage::disk('public')->put('sermons/audio/section-2.mp3', 'audio');
        // Video file absent — hasExtractedMedia() on the model returns false.

        $result = $this->action->execute($section);

        $this->assertSame('Section media is missing. Reclassify and prepare candidates again.', $result);
    }

    #[Test]
    public function it_returns_error_when_childrens_talk_has_no_resolved_speaker(): void
    {
        $section = $this->makePendingSection([
            'section_type' => ServiceSectionType::ShortTalk->value,
            'extracted_video_path' => 'sermons/sections/3/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-3.mp3',
            'metadata' => [
                'talk_speaker' => [
                    'predicted' => ['outcome' => 'ambiguous', 'preacher_name' => 'Someone', 'confidence' => 0.5],
                ],
            ],
        ]);

        Storage::disk('public')->put('sermons/sections/3/video.mp4', 'video');
        Storage::disk('public')->put('sermons/audio/section-3.mp3', 'audio');

        $result = $this->action->execute($section);

        $this->assertSame('Choose a speaker for this short talk before approving publication.', $result);
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    #[Test]
    public function it_approves_section_transitions_state_and_dispatches_publish_job(): void
    {
        Queue::fake();

        $section = $this->makePendingSection([
            'extracted_video_path' => 'sermons/sections/10/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-10.mp3',
        ]);

        Storage::disk('public')->put('sermons/sections/10/video.mp4', 'video');
        Storage::disk('public')->put('sermons/audio/section-10.mp3', 'audio');

        $result = $this->action->execute($section);

        $this->assertNull($result);

        $section->refresh();
        $metadata = $section->metadata?->toArray() ?? [];
        $this->assertSame(ServiceSectionPublicationStatus::Approved, $section->publication_status);
        $this->assertArrayHasKey('publication', $metadata);
        $this->assertArrayHasKey('approved_signature', $metadata['publication']);
        $this->assertArrayHasKey('approved_at', $metadata['publication']);

        Queue::assertPushed(PublishApprovedServiceSection::class);
    }

    #[Test]
    public function it_appends_batch_approvals_metadata_when_audit_metadata_is_provided(): void
    {
        Queue::fake();

        $section = $this->makePendingSection([
            'extracted_video_path' => 'sermons/sections/11/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-11.mp3',
        ]);

        Storage::disk('public')->put('sermons/sections/11/video.mp4', 'video');
        Storage::disk('public')->put('sermons/audio/section-11.mp3', 'audio');

        $auditMetadata = [
            'batch_id' => 'test-batch-uuid',
            'approved_by_user_id' => 1,
            'source' => 'test',
        ];

        $result = $this->action->execute($section, $auditMetadata);

        $this->assertNull($result);

        $section->refresh();
        $batchApprovals = $section->metadata['publication']['batch_approvals'] ?? null;
        $this->assertIsArray($batchApprovals);
        $this->assertCount(1, $batchApprovals);
        $this->assertSame('test-batch-uuid', $batchApprovals[0]['batch_id']);
    }

    #[Test]
    public function it_returns_error_when_section_cannot_transition_to_approved(): void
    {
        Queue::fake();

        // PUBLISHED → APPROVED is not an allowed transition
        $section = $this->makePendingSection([
            'publication_status' => ServiceSectionPublicationStatus::Published->value,
            'extracted_video_path' => 'sermons/sections/12/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-12.mp3',
        ]);

        Storage::disk('public')->put('sermons/sections/12/video.mp4', 'video');
        Storage::disk('public')->put('sermons/audio/section-12.mp3', 'audio');

        $result = $this->action->execute($section);

        $this->assertSame('This section cannot be approved in its current state.', $result);
        Queue::assertNothingPushed();
    }

    // -------------------------------------------------------------------------
    // approvalBlocker as a public method
    // -------------------------------------------------------------------------

    #[Test]
    public function approval_blocker_returns_null_when_section_is_ready(): void
    {
        $section = $this->makePendingSection([
            'extracted_video_path' => 'sermons/sections/20/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-20.mp3',
        ]);

        Storage::disk('public')->put('sermons/sections/20/video.mp4', 'video');
        Storage::disk('public')->put('sermons/audio/section-20.mp3', 'audio');

        $this->assertNull($this->action->approvalBlocker($section));
    }

    #[Test]
    public function a_short_talk_with_a_speaker_but_no_confirmed_type_cannot_be_approved(): void
    {
        $section = $this->readyShortTalk(['proposed' => 'childrens_talk']);

        $this->assertSame('Choose the talk type before approving publication.', $this->action->execute($section));
    }

    #[Test]
    public function a_confirmed_type_lets_a_short_talk_through(): void
    {
        Queue::fake();
        $section = $this->readyShortTalk(['proposed' => 'childrens_talk', 'reviewed' => ['value' => 'testimony']]);

        $this->assertNull($this->action->execute($section));
    }

    #[Test]
    public function the_signature_ignores_an_unconfirmed_type_and_includes_a_confirmed_one(): void
    {
        $section = $this->readyShortTalk(['proposed' => 'childrens_talk']);
        $unconfirmed = $section->classificationSignaturePayload();

        $section->metadata = ServiceSectionMetadata::fromArray(array_merge(
            $section->metadata?->toArray() ?? [],
            ['talk_type' => ['proposed' => 'childrens_talk', 'reviewed' => ['value' => 'testimony']]],
        ));

        $this->assertArrayNotHasKey('talk_type', $unconfirmed);
        $this->assertSame('testimony', $section->classificationSignaturePayload()['talk_type']);
    }

    /**
     * Acceptance 5: a type changed after approval refuses publication until re-approval.
     */
    #[Test]
    public function changing_the_type_after_approval_refuses_publication(): void
    {
        Queue::fake();
        $section = $this->readyShortTalk(['reviewed' => ['value' => 'testimony']]);
        $this->assertNull($this->action->execute($section));

        $section->refresh();
        $section->processingLog->update(['extracted_date' => '2026-05-31', 'extracted_service' => 'morning']);
        $metadata = $section->metadata?->toArray() ?? [];
        $metadata['talk_type']['reviewed']['value'] = 'partner_update';
        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
        $section->save();

        $this->expectExceptionMessage('Section classification changed since approval; re-approve before publishing');

        app(TalkPublicationHandler::class)->publish($section->refresh());
    }

    /**
     * @param  array<string, mixed>  $talkType
     */
    private function readyShortTalk(array $talkType): ServiceSection
    {
        Storage::disk('public')->put('sermons/sections/7/video.mp4', 'video');
        Storage::disk('public')->put('sermons/audio/section-7.mp3', 'audio');

        return $this->makePendingSection([
            'section_type' => ServiceSectionType::ShortTalk->value,
            'extracted_video_path' => 'sermons/sections/7/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-7.mp3',
            'metadata' => [
                'talk_speaker' => ['reviewed' => ['preacher_id' => null, 'preacher_name' => 'Visiting Speaker', 'source' => 'manual', 'confidence' => null]],
                'talk_type' => $talkType,
            ],
        ]);
    }
}
