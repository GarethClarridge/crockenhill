<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\SermonService;
use App\Enums\ServiceSectionPublicationStatus;
use App\Jobs\PublishApprovedServiceSection;
use App\Livewire\Admin\ChurchServices\ListSectionPublications;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminSectionPublicationQueueTest extends TestCase
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
    public function it_lists_sections_and_filters_by_publication_status(): void
    {
        $this->actingAs($this->admin);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-05-10',
            'extracted_service' => SermonService::MORNING->value,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'title' => "Children's Talk",
            'publication_status' => ServiceSectionPublicationStatus::PENDING_APPROVAL->value,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'title' => 'Rejected Section',
            'publication_status' => ServiceSectionPublicationStatus::REJECTED->value,
        ]);

        Livewire::test(ListSectionPublications::class)
            ->assertSee("Children's Talk")
            ->assertDontSee('Rejected Section')
            ->set('publicationStatus', ServiceSectionPublicationStatus::REJECTED->value)
            ->assertSee('Rejected Section')
            ->assertDontSee("Children's Talk");
    }

    #[Test]
    public function approve_action_marks_section_approved_and_dispatches_publish_job(): void
    {
        Queue::fake();
        $this->actingAs($this->admin);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-05-17',
            'extracted_service' => SermonService::MORNING->value,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'publication_status' => ServiceSectionPublicationStatus::PENDING_APPROVAL->value,
        ]);

        Livewire::test(ListSectionPublications::class)
            ->call('approve', $section->id)
            ->assertDispatched('notify', type: 'success', message: 'Section approved and publish job queued.');

        $section->refresh();
        $this->assertSame(ServiceSectionPublicationStatus::APPROVED, $section->publication_status);
        Queue::assertPushed(PublishApprovedServiceSection::class);
    }

    #[Test]
    public function reject_and_requeue_actions_update_publication_state(): void
    {
        $this->actingAs($this->admin);

        $run = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'publication_status' => ServiceSectionPublicationStatus::PENDING_APPROVAL->value,
        ]);

        Livewire::test(ListSectionPublications::class)
            ->call('reject', $section->id)
            ->assertDispatched('notify', type: 'success', message: 'Section rejected.')
            ->call('requeue', $section->id)
            ->assertDispatched('notify', type: 'success', message: 'Section moved back to pending approval.');

        $section->refresh();
        $this->assertSame(ServiceSectionPublicationStatus::PENDING_APPROVAL, $section->publication_status);
    }

    #[Test]
    public function non_admin_cannot_access_section_publications_component(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);

        Livewire::test(ListSectionPublications::class)
            ->assertForbidden();
    }
}
