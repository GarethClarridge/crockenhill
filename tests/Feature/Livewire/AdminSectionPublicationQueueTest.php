<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\SermonService;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Jobs\PublishApprovedServiceSection;
use App\Livewire\Admin\ChurchServices\ListSectionPublications;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\BuildsTestScenarios;

class AdminSectionPublicationQueueTest extends TestCase
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
    public function it_lists_sections_and_filters_by_publication_status(): void
    {
        $this->actingAs($this->admin);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-05-10',
            'extracted_service' => SermonService::Morning->value,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'title' => "Children's Talk",
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'section_order' => 1,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'title' => 'Rejected Section',
            'section_type' => ServiceSectionType::Welcome->value,
            'section_order' => 2,
            'publication_status' => ServiceSectionPublicationStatus::Rejected->value,
        ]);

        Livewire::test(ListSectionPublications::class)
            ->assertSee("Children's Talk")
            ->assertDontSee('Rejected Section')
            ->set('publicationStatus', ServiceSectionPublicationStatus::Rejected->value)
            ->assertSee('Rejected Section')
            ->assertDontSee("Children's Talk");
    }

    #[Test]
    public function approve_action_marks_section_approved_and_dispatches_publish_job(): void
    {
        Queue::fake();
        Storage::fake('public');
        $this->actingAs($this->admin);

        config(['media-processing.storage.sermon_disk' => 'public']);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-05-17',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Welcome->value,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
            'extracted_video_path' => 'sermons/sections/'.$run->id.'/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-'.$run->id.'.mp3',
        ]);

        Storage::disk('public')->put('sermons/sections/'.$run->id.'/video.mp4', 'video');
        Storage::disk('public')->put('sermons/audio/section-'.$run->id.'.mp3', 'audio');

        Livewire::test(ListSectionPublications::class)
            ->call('approve', $section->id)
            ->assertDispatched('notify', type: 'success', message: 'Section approved and publish job queued.');

        $section->refresh();
        $this->assertSame(ServiceSectionPublicationStatus::Approved, $section->publication_status);
        Queue::assertPushed(PublishApprovedServiceSection::class);
    }

    #[Test]
    public function approve_action_blocks_when_extracted_media_is_missing(): void
    {
        Queue::fake();
        Storage::fake('public');
        $this->actingAs($this->admin);

        config(['media-processing.storage.sermon_disk' => 'public']);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-05-18',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Welcome->value,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
            'extracted_video_path' => 'sermons/sections/'.$run->id.'/missing-video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-'.$run->id.'-missing.mp3',
        ]);

        Livewire::test(ListSectionPublications::class)
            ->call('approve', $section->id)
            ->assertDispatched(
                'notify',
                type: 'error',
                message: 'Section media is missing. Reclassify and prepare candidates again.'
            );

        $section->refresh();
        $this->assertSame(ServiceSectionPublicationStatus::PendingApproval, $section->publication_status);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function approve_action_blocks_childrens_talks_without_a_confirmed_speaker(): void
    {
        Queue::fake();
        Storage::fake('public');
        $this->actingAs($this->admin);

        config(['media-processing.storage.sermon_disk' => 'public']);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-05-19',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
            'extracted_video_path' => 'sermons/sections/'.$run->id.'/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-'.$run->id.'.mp3',
            'metadata' => [
                'childrens_talk_speaker' => [
                    'predicted' => [
                        'outcome' => 'ambiguous',
                        'preacher_name' => 'Detected Speaker',
                        'confidence' => 0.61,
                    ],
                ],
            ],
        ]);

        Storage::disk('public')->put('sermons/sections/'.$run->id.'/video.mp4', 'video');
        Storage::disk('public')->put('sermons/audio/section-'.$run->id.'.mp3', 'audio');

        Livewire::test(ListSectionPublications::class)
            ->call('approve', $section->id)
            ->assertDispatched(
                'notify',
                type: 'error',
                message: "Choose a speaker for this children's talk before approving publication."
            );

        $section->refresh();
        $this->assertSame(ServiceSectionPublicationStatus::PendingApproval, $section->publication_status);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function reject_and_requeue_actions_update_publication_state(): void
    {
        $this->actingAs($this->admin);

        $run = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
        ]);

        Livewire::test(ListSectionPublications::class)
            ->call('reject', $section->id)
            ->assertDispatched('notify', type: 'success', message: 'Section rejected.')
            ->call('requeue', $section->id)
            ->assertDispatched('notify', type: 'success', message: 'Section moved back to pending approval.');

        $section->refresh();
        $this->assertSame(ServiceSectionPublicationStatus::PendingApproval, $section->publication_status);
    }
}
