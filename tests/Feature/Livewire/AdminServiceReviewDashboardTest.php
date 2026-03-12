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

class AdminServiceReviewDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
    }

    #[Test]
    public function it_lists_flagged_services_and_sections_in_the_review_queue(): void
    {
        $this->actingAs($this->admin);

        $service = ChurchService::factory()->create([
            'date' => '2026-05-24',
            'service' => SermonService::MORNING,
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
            'extracted_service' => SermonService::MORNING->value,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $songItem->id,
            'section_type' => ServiceSectionType::SONG->value,
            'title' => 'Unknown Song',
            'needs_manual_review' => true,
            'confidence' => 0.72,
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
            'service' => SermonService::MORNING,
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
            'extracted_service' => SermonService::MORNING->value,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $confirmedItem->id,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 1,
            'title' => 'Confirmed Song',
            'confidence' => 0.72,
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
            'extracted_service' => SermonService::MORNING->value,
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
            'extracted_service' => SermonService::MORNING->value,
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
    public function correcting_section_type_and_title_persists(): void
    {
        $this->actingAs($this->admin);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-06-07',
            'extracted_service' => SermonService::EVENING->value,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::SONG->value,
            'title' => 'Misc item',
            'needs_manual_review' => true,
            'metadata' => [
                'review_reason' => 'oos_structure_mismatch',
            ],
        ]);

        Livewire::test(ServiceReviewDashboard::class)
            ->set("sectionEdits.{$section->id}.section_type", ServiceSectionType::PRAYER->value)
            ->set("sectionEdits.{$section->id}.title", 'Pastoral Prayer')
            ->call('saveSection', $section->id)
            ->assertDispatched('notify', type: 'success', message: 'Section changes saved.');

        $section->refresh();

        $this->assertSame(ServiceSectionType::PRAYER, $section->section_type);
        $this->assertSame('Pastoral Prayer', $section->title);
        $this->assertFalse($section->needs_manual_review);
    }

    #[Test]
    public function it_allows_admins_to_override_childrens_talk_speakers_and_queue_publication_review(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin);

        config(['media-processing.storage.sermon_disk' => 'public']);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-06-08',
            'extracted_service' => SermonService::MORNING->value,
        ]);

        $overridePreacher = Preacher::factory()->create(['name' => 'Mary Helper']);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::CHILDRENS_TALK->value,
            'title' => "Children's Talk",
            'needs_manual_review' => true,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
            'extracted_video_path' => 'sermons/sections/override/video.mp4',
            'extracted_audio_path' => 'sermons/audio/override.mp3',
            'metadata' => [
                'review_reason' => 'childrens_talk_speaker_ambiguous',
                'childrens_talk_speaker' => [
                    'predicted' => [
                        'outcome' => 'ambiguous',
                        'preacher_name' => 'Detected Speaker',
                        'confidence' => 0.64,
                        'reason' => 'Two profiles scored too closely to auto-accept.',
                    ],
                ],
            ],
        ]);

        Storage::disk('public')->put('sermons/sections/override/video.mp4', 'video');
        Storage::disk('public')->put('sermons/audio/override.mp3', 'audio');

        Livewire::test(ServiceReviewDashboard::class)
            ->assertSee('Detected Speaker')
            ->set("speakerEdits.{$section->id}.preacher_id", (string) $overridePreacher->id)
            ->set("speakerEdits.{$section->id}.speaker_name", '')
            ->call('saveSection', $section->id)
            ->assertDispatched('notify', type: 'success', message: 'Section changes saved.');

        $section->refresh();

        $this->assertFalse($section->needs_manual_review);
        $this->assertSame(ServiceSectionPublicationStatus::PENDING_APPROVAL, $section->publication_status);
        $this->assertSame('Mary Helper', $section->metadata['childrens_talk_speaker']['reviewed']['preacher_name'] ?? null);
        $this->assertSame('manual', $section->metadata['childrens_talk_speaker']['reviewed']['source'] ?? null);
    }

    #[Test]
    public function save_section_requires_valid_type_and_title(): void
    {
        $this->actingAs($this->admin);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-06-14',
            'extracted_service' => SermonService::MORNING->value,
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
            'extracted_service' => SermonService::MORNING->value,
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
    public function marking_a_service_reviewed_clears_the_review_flag(): void
    {
        $this->actingAs($this->admin);

        $service = ChurchService::factory()->create([
            'needs_review' => true,
            'import_metadata' => [
                'canonical_conflict' => [
                    'detected_at' => now()->subHour()->toIso8601String(),
                    'incoming_source' => 'openlp',
                ],
                'canonical_conflict_history' => [[
                    'detected_at' => now()->subHour()->toIso8601String(),
                    'incoming_source' => 'openlp',
                ]],
            ],
        ]);

        Livewire::test(ServiceReviewDashboard::class)
            ->call('markServiceReviewed', $service->id)
            ->assertDispatched('notify', type: 'success', message: 'Service marked as reviewed.');

        $this->assertFalse($service->fresh()->needs_review);
        $this->assertArrayNotHasKey('canonical_conflict', $service->fresh()->import_metadata ?? []);
        $this->assertCount(1, $service->fresh()->import_metadata['canonical_conflict_history'] ?? []);
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
            'service' => SermonService::EVENING,
            'needs_review' => true,
        ]);

        Livewire::test(ServiceReviewDashboard::class)
            ->assertSee('28 Jun 2026')
            ->assertSee('Evening')
            ->assertSee('This service only has a service-level review flag.');
    }

    #[Test]
    public function non_admin_cannot_access_the_review_dashboard(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);

        Livewire::test(ServiceReviewDashboard::class)
            ->assertForbidden();
    }
}
