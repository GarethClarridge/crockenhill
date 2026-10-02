<?php

declare(strict_types=1);

namespace Tests\Feature\Actions\ServiceReview;

use App\Actions\HoldSectionForContentReview;
use App\Actions\ServiceReview\SaveServiceSection;
use App\Enums\SermonService;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Enums\TalkType;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\ServiceSection;
use App\Models\User;
use App\Services\Sermon\SermonExtractionPlanResolver;
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
            'section_type' => ServiceSectionType::Song->value,
            'title' => 'Misc item',
            'needs_manual_review' => true,
            'metadata' => ['review_reason' => 'oos_structure_mismatch'],
        ]);

        $this->action->execute(
            section: $section,
            sectionEdits: [$section->id => ['section_type' => ServiceSectionType::Prayer->value, 'title' => 'Pastoral Prayer']],
            speakerEdits: [],
            userId: $this->admin->id,
        );

        $section->refresh();
        $metadata = $section->metadata?->toArray() ?? [];

        $this->assertSame(ServiceSectionType::Prayer, $section->section_type);
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
            sectionEdits: [$section->id => ['section_type' => ServiceSectionType::Prayer->value, 'title' => '']],
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
            'section_type' => ServiceSectionType::Song->value,
            'needs_manual_review' => true,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
        ]);

        $this->action->execute(
            section: $section,
            sectionEdits: [$section->id => ['section_type' => ServiceSectionType::Prayer->value, 'title' => 'Prayer']],
            speakerEdits: [],
            userId: $this->admin->id,
        );

        $section->refresh();

        $this->assertSame(ServiceSectionPublicationStatus::NotApplicable, $section->publication_status);
    }

    #[Test]
    public function it_transitions_to_pending_approval_when_publishable_and_media_exists(): void
    {
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $run = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::ShortTalk->value,
            'title' => "Children's Talk",
            'needs_manual_review' => true,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
            'extracted_video_path' => 'sections/video.mp4',
            'extracted_audio_path' => 'sections/audio.mp3',
            'metadata' => [
                'review_reason' => 'talk_speaker_ambiguous',
                'talk_speaker' => [
                    'predicted' => ['outcome' => 'ambiguous', 'preacher_name' => 'Someone'],
                ],
            ],
        ]);

        Storage::disk('public')->put('sections/video.mp4', 'video');
        Storage::disk('public')->put('sections/audio.mp3', 'audio');

        $preacher = Preacher::factory()->create();

        $this->action->execute(
            section: $section,
            sectionEdits: [$section->id => ['section_type' => ServiceSectionType::ShortTalk->value, 'title' => "Children's Talk"]],
            speakerEdits: [$section->id => ['preacher_id' => (string) $preacher->id, 'speaker_name' => '']],
            userId: $this->admin->id,
        );

        $section->refresh();

        $this->assertFalse($section->needs_manual_review);
        $this->assertSame(ServiceSectionPublicationStatus::PendingApproval, $section->publication_status);
    }

    #[Test]
    public function it_reextracts_a_childrens_talk_when_a_reviewer_shortens_the_inclusive_end(): void
    {
        Bus::fake();
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $run = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::ShortTalk->value,
            'title' => "Children's Talk",
            'start_time' => 600.0,
            'end_time' => 900.0,
            'duration' => 300.0,
            'needs_manual_review' => false,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
            'extracted_video_path' => 'section-publications/child/video.mp4',
            'extracted_audio_path' => 'section-publications/child/audio.mp3',
            'extracted_at' => now()->subMinute(),
            'unpublished_expires_at' => now()->addHours(48),
            'metadata' => [
                'talk_speaker' => [
                    'reviewed' => [
                        'preacher_id' => null,
                        'preacher_name' => 'Mary Helper',
                        'source' => 'manual',
                    ],
                ],
                'short_talk_boundary' => [
                    'candidate' => [
                        'kind' => 'inclusive',
                        'start_time' => 600.0,
                        'end_time' => 900.0,
                    ],
                ],
                'publication' => [
                    'approved_signature' => 'old-signature',
                    'approved_at' => now()->subMinute()->toIso8601String(),
                ],
            ],
        ]);

        $this->action->execute(
            section: $section,
            sectionEdits: [$section->id => [
                'section_type' => ServiceSectionType::ShortTalk->value,
                'title' => "Children's Talk",
                'end_time' => '760.500',
            ]],
            speakerEdits: [],
            userId: $this->admin->id,
        );

        $section->refresh();
        $metadata = $section->metadata?->toArray() ?? [];

        $this->assertSame(600.0, (float) $section->start_time);
        $this->assertSame(760.5, (float) $section->end_time);
        $this->assertSame(160.5, (float) $section->duration);
        $this->assertSame(ServiceSectionPublicationStatus::PendingApproval, $section->publication_status);
        $this->assertNull($section->extracted_video_path);
        $this->assertNull($section->extracted_audio_path);
        $this->assertNull($section->extracted_at);
        $this->assertNull($section->unpublished_expires_at);
        $this->assertArrayNotHasKey('approved_signature', $metadata['publication'] ?? []);
        $this->assertSame(760.5, $metadata['short_talk_boundary']['reviewed_recuts'][0]['to']['end_time']);
        Bus::assertDispatched(PrepareSectionPublicationCandidates::class);
    }

    #[Test]
    public function it_rejects_a_childrens_talk_recut_that_extends_the_inclusive_candidate(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::ShortTalk->value,
            'start_time' => 600.0,
            'end_time' => 900.0,
            'needs_manual_review' => false,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
            'metadata' => [
                'talk_speaker' => [
                    'reviewed' => [
                        'preacher_id' => null,
                        'preacher_name' => 'Mary Helper',
                        'source' => 'manual',
                    ],
                ],
            ],
        ]);

        $this->expectException(ValidationException::class);

        $this->action->execute(
            section: $section,
            sectionEdits: [$section->id => [
                'section_type' => ServiceSectionType::ShortTalk->value,
                'title' => "Children's Talk",
                'end_time' => '901',
            ]],
            speakerEdits: [],
            userId: $this->admin->id,
        );
    }

    #[Test]
    public function it_dispatches_prepare_candidates_when_publishable_but_no_media_extracted(): void
    {
        Bus::fake();

        $run = MediaProcessingLog::factory()->livestream()->create();

        $preacher = Preacher::factory()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::ShortTalk->value,
            'title' => "Children's Talk",
            'needs_manual_review' => true,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
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
            sectionEdits: [$section->id => ['section_type' => ServiceSectionType::ShortTalk->value, 'title' => "Children's Talk"]],
            speakerEdits: [$section->id => ['preacher_id' => (string) $preacher->id, 'speaker_name' => '']],
            userId: $this->admin->id,
        );

        $section->refresh();

        $this->assertFalse($section->needs_manual_review);
        $this->assertSame(ServiceSectionPublicationStatus::NotApplicable, $section->publication_status);
        Bus::assertDispatched(PrepareSectionPublicationCandidates::class);
    }

    #[Test]
    public function it_requires_speaker_for_childrens_talk_with_no_existing_speaker(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::ShortTalk->value,
            'needs_manual_review' => true,
        ]);

        $this->expectException(ValidationException::class);

        $this->action->execute(
            section: $section,
            sectionEdits: [$section->id => ['section_type' => ServiceSectionType::ShortTalk->value, 'title' => "Children's Talk"]],
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
            'section_type' => ServiceSectionType::Welcome->value,
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

        $this->assertSame(ServiceSectionType::Welcome, $section->section_type);
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
            'section_type' => ServiceSectionType::Welcome->value,
            'title' => 'Old Title',
            'needs_manual_review' => false,
            'confidence' => 0.99,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        $this->action->execute(
            section: $section,
            sectionEdits: [$section->id => ['section_type' => ServiceSectionType::Prayer->value, 'title' => 'Updated Title']],
            speakerEdits: [],
            userId: $this->admin->id,
        );

        $section->refresh();

        $this->assertSame(ServiceSectionType::Prayer, $section->section_type);
        $this->assertSame('Updated Title', $section->title);
    }

    #[Test]
    public function it_records_the_confirmed_talk_type_with_who_and_when_beside_the_proposal(): void
    {
        $section = $this->shortTalk(['talk_type' => ['proposed' => 'childrens_talk']]);

        $this->action->execute(
            section: $section,
            sectionEdits: [$section->id => ['section_type' => ServiceSectionType::ShortTalk->value, 'title' => 'Mission update', 'talk_type' => 'partner_update']],
            speakerEdits: [$section->id => ['preacher_id' => '', 'speaker_name' => 'Visiting Speaker']],
            userId: $this->admin->id,
        );

        $talkType = $section->refresh()->metadata?->talkType;

        $this->assertSame(TalkType::ChildrensTalk, $talkType?->proposed);
        $this->assertSame(TalkType::PartnerUpdate, $section->publicationTalkType());
        $this->assertSame($this->admin->id, $talkType?->reviewed['user_id'] ?? null);
        $this->assertNotEmpty($talkType?->reviewed['at'] ?? null);
    }

    #[Test]
    public function saving_without_a_talk_type_leaves_it_unconfirmed(): void
    {
        $section = $this->shortTalk(['talk_type' => ['proposed' => 'testimony']]);

        $this->action->execute(
            section: $section,
            sectionEdits: [$section->id => ['section_type' => ServiceSectionType::ShortTalk->value, 'title' => 'A talk']],
            speakerEdits: [$section->id => ['preacher_id' => '', 'speaker_name' => 'Visiting Speaker']],
            userId: $this->admin->id,
        );

        $this->assertNull($section->refresh()->publicationTalkType());
    }

    #[Test]
    public function the_sermon_is_not_a_talk_type_a_short_talk_can_take(): void
    {
        $section = $this->shortTalk();

        $this->expectException(ValidationException::class);

        $this->action->execute(
            section: $section,
            sectionEdits: [$section->id => ['section_type' => ServiceSectionType::ShortTalk->value, 'title' => 'A talk', 'talk_type' => 'sermon']],
            speakerEdits: [$section->id => ['preacher_id' => '', 'speaker_name' => 'Visiting Speaker']],
            userId: $this->admin->id,
        );
    }

    #[Test]
    public function retyping_away_from_a_short_talk_drops_the_talk_type(): void
    {
        $section = $this->shortTalk(['talk_type' => ['proposed' => 'testimony', 'reviewed' => ['value' => 'testimony']]]);

        $this->action->execute(
            section: $section,
            sectionEdits: [$section->id => ['section_type' => ServiceSectionType::Prayer->value, 'title' => 'Prayer']],
            speakerEdits: [],
            userId: $this->admin->id,
        );

        $this->assertArrayNotHasKey('talk_type', $section->refresh()->metadata?->toArray() ?? []);
    }

    #[Test]
    public function reviewing_sermon_membership_records_the_selection_and_keeps_an_existing_content_hold(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create(['duration' => 400]);
        $sermon = ServiceSection::factory()->create(['media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon, 'title' => 'Held sermon', 'start_time' => 100, 'end_time' => 300,
            'needs_manual_review' => true, 'metadata' => ['review_flags' => [HoldSectionForContentReview::FLAG]]]);
        $reading = ServiceSection::factory()->create(['media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::BibleReading, 'start_time' => 10, 'end_time' => 90, 'needs_manual_review' => false]);
        $resolver = app(SermonExtractionPlanResolver::class);
        $composition = $resolver->compose($log);
        $this->action->execute($sermon->fresh(), [$sermon->id => [
            'sermon_section_ids' => "{$reading->id}, {$sermon->id}",
            'sermon_composition_identity' => $composition['input_identity'],
        ]], [], $this->admin->id);

        $this->assertSame([$reading->id, $sermon->id], $log->fresh()->processing_metadata->raw['sermon_composition']['selected_section_ids']);
        $this->assertContains(HoldSectionForContentReview::FLAG, $sermon->fresh()->metadata->reviewFlags);
        $this->assertTrue($resolver->resolve($log->fresh())['metadata']['requires_review']);
        $this->assertSame('sermon_section_content_held', $resolver->resolve($log->fresh())['metadata']['reason']);
    }

    #[Test]
    public function retyping_a_selected_reading_recomposes_the_sermon_immediately(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create(['duration' => 400]);
        $sermon = ServiceSection::factory()->create(['media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon, 'start_time' => 100, 'end_time' => 300,
            'needs_manual_review' => false, 'metadata' => ['sermon_reference' => 'John 3:16']]);
        $reading = ServiceSection::factory()->create(['media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::BibleReading, 'start_time' => 10, 'end_time' => 90,
            'metadata' => ['reading_reference' => 'John 3:16'], 'needs_manual_review' => true]);
        $resolver = app(SermonExtractionPlanResolver::class);
        $composition = $resolver->compose($log);
        $resolver->reviewComposition($log, [$reading->id, $sermon->id], $composition['input_identity'], $this->admin->id);

        $this->action->execute($reading, [$reading->id => ['section_type' => ServiceSectionType::Other->value, 'title' => 'Introduction']], [], $this->admin->id);

        $current = $log->fresh()->processing_metadata->raw['sermon_composition'];
        $this->assertNotSame($composition['input_identity'], $current['input_identity']);
        $this->assertSame([$sermon->id], $current['selected_section_ids']);
    }

    #[Test]
    public function removing_the_only_sermon_invalidates_its_stored_composition(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create(['duration' => 400]);
        $sermon = ServiceSection::factory()->create(['media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon, 'start_time' => 100, 'end_time' => 300,
            'needs_manual_review' => true]);
        app(SermonExtractionPlanResolver::class)->compose($log);

        $this->action->execute($sermon->fresh(), [$sermon->id => ['section_type' => ServiceSectionType::Other->value, 'title' => 'Introduction']], [], $this->admin->id);

        $current = $log->fresh()->processing_metadata->raw['sermon_composition'];
        $this->assertSame([], $current['selected_section_ids']);
        $this->assertTrue($current['requires_review']);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function shortTalk(array $metadata = []): ServiceSection
    {
        Bus::fake([PrepareSectionPublicationCandidates::class]);

        return ServiceSection::factory()->create([
            'media_processing_log_id' => MediaProcessingLog::factory()->livestream()->create()->id,
            'section_type' => ServiceSectionType::ShortTalk->value,
            'needs_manual_review' => true,
            'metadata' => $metadata,
        ]);
    }
}
