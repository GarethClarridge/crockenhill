<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\SermonService;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Jobs\PublishApprovedServiceSection;
use App\Livewire\Admin\ChurchServices\ServiceReviewDashboard;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\ServiceSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\BuildsTestScenarios;

class AdminServiceReviewDashboardTest extends TestCase
{
    use BuildsTestScenarios;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createVerifiedAdmin();
    }

    #[Test]
    public function it_lists_flagged_services_and_sections_in_the_review_queue(): void
    {
        $this->actingAs($this->admin);

        $service = ChurchService::factory()->create([
            'date' => '2026-05-24',
            'service' => SermonService::Morning,
            'needs_review' => true,
        ]);

        $songItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Opening Song',
            'song_id' => null,
        ]);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $service->id,
            'extracted_date' => '2026-05-24',
            'extracted_service' => SermonService::Morning->value,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $songItem->id,
            'section_type' => ServiceSectionType::SONG->value,
            'title' => 'Unknown Song',
            'needs_manual_review' => true,
            'confidence' => 0.72,
            'song_match_type' => 'unmatched',
            'metadata' => [
                'review_reason' => 'expected_type_mismatch',
                'oos_alignment' => [
                    'song_match_type' => 'unmatched',
                ],
            ],
            'publication_status' => ServiceSectionPublicationStatus::PENDING_APPROVAL->value,
        ]);

        $this->get(route('admin.services.review'))
            ->assertOk()
            ->assertSeeLivewire(ServiceReviewDashboard::class);

        Livewire::test(ServiceReviewDashboard::class)
            ->assertSee('24 May 2026')
            ->assertSee('Morning')
            ->assertSee('Unknown Song')
            ->assertSee('Manual review')
            ->assertSee('Pending approval')
            ->assertSee('Low confidence')
            ->assertSee('Unmatched song')
            ->assertSee('Service needs review');
    }

    #[Test]
    public function it_distinguishes_inferred_song_labels_from_confirmed_song_matches(): void
    {
        $this->actingAs($this->admin);

        $service = ChurchService::factory()->create([
            'date' => '2026-05-25',
            'service' => SermonService::Morning,
            'needs_review' => true,
        ]);

        $confirmedItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Confirmed Song',
        ]);

        $inferredItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 2,
            'type' => 'songs',
            'title' => 'Inferred Song',
            'song_id' => null,
        ]);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $service->id,
            'extracted_date' => '2026-05-25',
            'extracted_service' => SermonService::Morning->value,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $confirmedItem->id,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 1,
            'title' => 'Confirmed Song',
            'confidence' => 0.72,
            'song_match_type' => 'confirmed',
            'metadata' => [
                'oos_alignment' => [
                    'song_match_type' => 'confirmed',
                ],
            ],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $inferredItem->id,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 2,
            'title' => 'Inferred Song',
            'needs_manual_review' => true,
            'confidence' => 0.72,
            'song_match_type' => 'inferred',
            'metadata' => [
                'review_reason' => 'song_alignment_inferred',
                'oos_alignment' => [
                    'song_match_type' => 'inferred',
                ],
            ],
        ]);

        Livewire::test(ServiceReviewDashboard::class)
            ->assertSee('Confirmed song match')
            ->assertSee('Inferred song label')
            ->assertDontSee('Unmatched song');
    }

    #[Test]
    public function approve_and_reject_actions_update_publication_status(): void
    {
        Queue::fake();
        Storage::fake('public');
        $this->actingAs($this->admin);

        config(['media-processing.storage.sermon_disk' => 'public']);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-05-31',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $approveSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::WELCOME->value,
            'section_order' => 1,
            'publication_status' => ServiceSectionPublicationStatus::PENDING_APPROVAL->value,
            'extracted_video_path' => 'sermons/sections/'.$run->id.'/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-'.$run->id.'.mp3',
        ]);

        $rejectSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::WELCOME->value,
            'section_order' => 2,
            'publication_status' => ServiceSectionPublicationStatus::PENDING_APPROVAL->value,
        ]);

        Storage::disk('public')->put('sermons/sections/'.$run->id.'/video.mp4', 'video');
        Storage::disk('public')->put('sermons/audio/section-'.$run->id.'.mp3', 'audio');

        Livewire::test(ServiceReviewDashboard::class)
            ->call('approve', $approveSection->id)
            ->assertDispatched('notify', type: 'success', message: 'Section approved and publish job queued.')
            ->call('reject', $rejectSection->id)
            ->assertDispatched('notify', type: 'success', message: 'Section rejected.');

        $approveSection->refresh();
        $rejectSection->refresh();

        $this->assertSame(ServiceSectionPublicationStatus::APPROVED, $approveSection->publication_status);
        $this->assertSame(ServiceSectionPublicationStatus::REJECTED, $rejectSection->publication_status);
        Queue::assertPushed(PublishApprovedServiceSection::class);
    }

    #[Test]
    public function requeue_action_updates_rejected_sections_in_dashboard_context(): void
    {
        $this->actingAs($this->admin);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-06-01',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'publication_status' => ServiceSectionPublicationStatus::REJECTED->value,
        ]);

        Livewire::test(ServiceReviewDashboard::class)
            ->call('requeue', $section->id)
            ->assertDispatched('notify', type: 'success', message: 'Section moved back to pending approval.');

        $this->assertSame(ServiceSectionPublicationStatus::PENDING_APPROVAL, $section->fresh()->publication_status);
    }

    #[Test]
    public function save_section_requires_valid_type_and_title(): void
    {
        $this->actingAs($this->admin);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-06-14',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'needs_manual_review' => true,
        ]);

        Livewire::test(ServiceReviewDashboard::class)
            ->set("sectionEdits.{$section->id}.section_type", 'invalid')
            ->set("sectionEdits.{$section->id}.title", '')
            ->call('saveSection', $section->id)
            ->assertHasErrors([
                'section_type',
                'title',
            ]);
    }

    #[Test]
    public function it_blocks_saving_a_section_that_is_no_longer_in_the_review_queue(): void
    {
        $this->actingAs($this->admin);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-06-21',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'needs_manual_review' => false,
            'confidence' => 0.98,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
            'section_type' => ServiceSectionType::WELCOME->value,
        ]);

        Livewire::test(ServiceReviewDashboard::class)
            ->set("sectionEdits.{$section->id}.section_type", ServiceSectionType::PRAYER->value)
            ->set("sectionEdits.{$section->id}.title", 'Updated Title')
            ->call('saveSection', $section->id)
            ->assertDispatched('notify', type: 'error', message: 'Section is no longer awaiting review.');

        $section->refresh();

        $this->assertSame(ServiceSectionType::WELCOME, $section->section_type);
        $this->assertNotSame('Updated Title', $section->title);
    }

    #[Test]
    public function it_renders_the_empty_state_when_nothing_requires_review(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ServiceReviewDashboard::class)
            ->assertSee('No services or sections are awaiting review.');
    }

    #[Test]
    public function it_renders_a_service_only_review_group_without_sections(): void
    {
        $this->actingAs($this->admin);

        ChurchService::factory()->create([
            'date' => '2026-06-28',
            'service' => SermonService::Evening,
            'needs_review' => true,
        ]);

        Livewire::test(ServiceReviewDashboard::class)
            ->assertSee('28 Jun 2026')
            ->assertSee('Evening')
            ->assertSee('This service only has a service-level review flag.');
    }

    #[Test]
    public function it_shows_a_merge_button_between_adjacent_same_type_sections(): void
    {
        $this->actingAs($this->admin);

        config(['media-processing.section_classification.adjacent_merge_max_gap_seconds' => 2]);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-07-06',
            'extracted_service' => SermonService::Morning->value,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 1,
            'needs_manual_review' => true,
            'start_time' => 100.0,
            'end_time' => 107.0,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 2,
            'needs_manual_review' => true,
            'start_time' => 107.0,
            'end_time' => 231.0,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
        ]);

        Livewire::test(ServiceReviewDashboard::class)
            ->assertSee('Merge these Song sections');
    }

    #[Test]
    public function it_does_not_show_merge_button_for_adjacent_different_type_sections(): void
    {
        $this->actingAs($this->admin);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-07-07',
            'extracted_service' => SermonService::Morning->value,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::PRAYER->value,
            'section_order' => 1,
            'needs_manual_review' => true,
            'start_time' => 0.0,
            'end_time' => 15.0,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::BIBLE_READING->value,
            'section_order' => 2,
            'needs_manual_review' => true,
            'start_time' => 15.0,
            'end_time' => 195.0,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
        ]);

        Livewire::test(ServiceReviewDashboard::class)
            ->assertDontSee('Merge these');
    }

    #[Test]
    public function it_shows_confirmation_panel_when_merge_is_initiated(): void
    {
        $this->actingAs($this->admin);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-07-08',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $first = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 1,
            'needs_manual_review' => true,
            'start_time' => 100.0,
            'end_time' => 107.0,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
        ]);

        $second = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 2,
            'needs_manual_review' => true,
            'start_time' => 107.0,
            'end_time' => 231.0,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
        ]);

        Livewire::test(ServiceReviewDashboard::class)
            ->call('initiateMerge', $first->id, $second->id)
            ->assertSee('Merge these two Song sections into one?')
            ->assertSee('Confirm merge');
    }

    #[Test]
    public function it_cancels_merge_on_cancel(): void
    {
        $this->actingAs($this->admin);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-07-09',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $first = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 1,
            'needs_manual_review' => true,
            'start_time' => 100.0,
            'end_time' => 107.0,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
        ]);

        $second = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 2,
            'needs_manual_review' => true,
            'start_time' => 107.0,
            'end_time' => 231.0,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
        ]);

        Livewire::test(ServiceReviewDashboard::class)
            ->call('initiateMerge', $first->id, $second->id)
            ->assertSee('Confirm merge')
            ->call('cancelMerge')
            ->assertDontSee('Confirm merge');
    }

    #[Test]
    public function it_rejects_merge_when_sections_are_from_different_processing_runs(): void
    {
        Queue::fake();
        $this->actingAs($this->admin);

        $runA = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-07-15',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $runB = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-07-15',
            'extracted_service' => SermonService::Evening->value,
        ]);

        $first = ServiceSection::factory()->create([
            'media_processing_log_id' => $runA->id,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 1,
            'needs_manual_review' => true,
            'start_time' => 100.0,
            'end_time' => 107.0,
        ]);

        $second = ServiceSection::factory()->create([
            'media_processing_log_id' => $runB->id,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 1,
            'needs_manual_review' => true,
            'start_time' => 107.0,
            'end_time' => 231.0,
        ]);

        Livewire::test(ServiceReviewDashboard::class)
            ->call('initiateMerge', $first->id, $second->id)
            ->call('confirmMerge')
            ->assertDispatched('notify', type: 'error', message: 'Both sections must belong to the same processing run.');

        $this->assertDatabaseHas('service_sections', ['id' => $first->id]);
        $this->assertDatabaseHas('service_sections', ['id' => $second->id]);
    }

    #[Test]
    public function it_rejects_merge_when_sections_are_of_different_types(): void
    {
        Queue::fake();
        $this->actingAs($this->admin);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-07-16',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $first = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 1,
            'needs_manual_review' => true,
            'start_time' => 100.0,
            'end_time' => 107.0,
        ]);

        $second = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::BIBLE_READING->value,
            'section_order' => 2,
            'needs_manual_review' => true,
            'start_time' => 107.0,
            'end_time' => 231.0,
        ]);

        Livewire::test(ServiceReviewDashboard::class)
            ->call('initiateMerge', $first->id, $second->id)
            ->call('confirmMerge')
            ->assertDispatched('notify', type: 'error', message: 'Both sections must have the same section type.');
    }

    #[Test]
    public function it_rejects_merge_when_a_section_is_published(): void
    {
        Queue::fake();
        $this->actingAs($this->admin);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-07-17',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $first = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 1,
            'needs_manual_review' => true,
            'start_time' => 100.0,
            'end_time' => 107.0,
            'publication_status' => ServiceSectionPublicationStatus::PUBLISHED->value,
        ]);

        $second = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 2,
            'needs_manual_review' => true,
            'start_time' => 107.0,
            'end_time' => 231.0,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
        ]);

        Livewire::test(ServiceReviewDashboard::class)
            ->call('initiateMerge', $first->id, $second->id)
            ->call('confirmMerge')
            ->assertDispatched('notify', type: 'error', message: 'Published sections cannot be merged.');
    }

    #[Test]
    public function it_rejects_merge_when_a_non_flagged_section_exists_between_the_two_candidates(): void
    {
        Queue::fake();
        $this->actingAs($this->admin);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-07-18',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $first = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 5,
            'needs_manual_review' => true,
            'start_time' => 100.0,
            'end_time' => 107.0,
        ]);

        // Un-flagged section at order 6 (between the merge candidates by section_order)
        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::PRAYER->value,
            'section_order' => 6,
            'needs_manual_review' => false,
            'start_time' => 300.0,
            'end_time' => 400.0,
        ]);

        // $third starts close to $first (gap 1.5s ≤ max 2s) so the gap check passes
        // and the between-sections check (section_order 6 exists between 5 and 7) triggers
        $third = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 7,
            'needs_manual_review' => true,
            'start_time' => 108.5,
            'end_time' => 244.0,
        ]);

        Livewire::test(ServiceReviewDashboard::class)
            ->call('initiateMerge', $first->id, $third->id)
            ->call('confirmMerge')
            ->assertDispatched('notify', type: 'error', message: 'There are other sections between these two — they cannot be merged.');

        $this->assertDatabaseHas('service_sections', ['id' => $first->id]);
        $this->assertDatabaseHas('service_sections', ['id' => $third->id]);
    }

    #[Test]
    public function save_section_wires_livewire_edits_through_to_persistence_and_dispatches_notify(): void
    {
        $this->actingAs($this->admin);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-07-01',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::SONG->value,
            'title' => 'Original Title',
            'needs_manual_review' => true,
        ]);

        Livewire::test(ServiceReviewDashboard::class)
            ->set("sectionEdits.{$section->id}.section_type", ServiceSectionType::PRAYER->value)
            ->set("sectionEdits.{$section->id}.title", 'Updated Title')
            ->call('saveSection', $section->id)
            ->assertDispatched('notify', type: 'success', message: 'Section changes saved.');

        $section->refresh();

        $this->assertSame(ServiceSectionType::PRAYER, $section->section_type);
        $this->assertSame('Updated Title', $section->title);
        $this->assertFalse($section->needs_manual_review);
    }

    #[Test]
    public function save_section_wires_speaker_edits_through_to_persistence_and_dispatches_notify(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin);

        config(['media-processing.storage.sermon_disk' => 'public']);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-07-02',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $preacher = Preacher::factory()->create(['name' => 'Rev Override']);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::CHILDRENS_TALK->value,
            'title' => "Children's Talk",
            'needs_manual_review' => true,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
            'extracted_video_path' => 'sermons/sections/speaker-test/video.mp4',
            'extracted_audio_path' => 'sermons/audio/speaker-test.mp3',
            'metadata' => [
                'review_reason' => 'childrens_talk_speaker_ambiguous',
                'childrens_talk_speaker' => [
                    'predicted' => ['outcome' => 'ambiguous', 'preacher_name' => 'Unknown', 'confidence' => 0.5, 'reason' => 'Too close to call.'],
                ],
            ],
        ]);

        Storage::disk('public')->put('sermons/sections/speaker-test/video.mp4', 'video');
        Storage::disk('public')->put('sermons/audio/speaker-test.mp3', 'audio');

        Livewire::test(ServiceReviewDashboard::class)
            ->set("speakerEdits.{$section->id}.preacher_id", (string) $preacher->id)
            ->set("speakerEdits.{$section->id}.speaker_name", '')
            ->call('saveSection', $section->id)
            ->assertDispatched('notify', type: 'success', message: 'Section changes saved.');

        $section->refresh();

        $this->assertFalse($section->needs_manual_review);
        $this->assertSame(ServiceSectionPublicationStatus::PENDING_APPROVAL, $section->publication_status);
        $this->assertSame('Rev Override', $section->metadata['childrens_talk_speaker']['reviewed']['preacher_name'] ?? null);
    }

    #[Test]
    public function approve_pending_publications_wires_through_to_batch_action_and_dispatches_notify(): void
    {
        Queue::fake();
        Storage::fake('public');
        $this->actingAs($this->admin);

        config(['media-processing.storage.sermon_disk' => 'public']);

        $service = ChurchService::factory()->create([
            'date' => '2026-07-03',
            'service' => SermonService::Morning,
        ]);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $service->id,
            'extracted_date' => '2026-07-03',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::WELCOME->value,
            'section_order' => 1,
            'publication_status' => ServiceSectionPublicationStatus::PENDING_APPROVAL->value,
            'extracted_video_path' => 'sermons/sections/batch-journey/video.mp4',
            'extracted_audio_path' => 'sermons/audio/batch-journey.mp3',
        ]);

        Storage::disk('public')->put('sermons/sections/batch-journey/video.mp4', 'video');
        Storage::disk('public')->put('sermons/audio/batch-journey.mp3', 'audio');

        Livewire::test(ServiceReviewDashboard::class)
            ->call('approvePendingPublications', $service->id)
            ->assertDispatched('notify', type: 'success', message: 'Approved all 1 pending publication for this service.');

        $this->assertSame(ServiceSectionPublicationStatus::APPROVED, $section->fresh()->publication_status);
        Queue::assertPushed(PublishApprovedServiceSection::class);
    }

    #[Test]
    public function non_admin_cannot_access_the_review_dashboard(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('admin.services.review'))
            ->assertForbidden();
    }
}
