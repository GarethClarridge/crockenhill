<?php

declare(strict_types=1);

namespace Tests\Feature\Actions\ServiceReview;

use App\Actions\Publication\ApproveSectionForPublication;
use App\Actions\ServiceReview\BatchApproveServicePublications;
use App\Enums\SermonService;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Jobs\PublishApprovedServiceSection;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BatchApproveServicePublicationsTest extends TestCase
{
    use RefreshDatabase;

    private BatchApproveServicePublications $action;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(BatchApproveServicePublications::class);
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    #[Test]
    public function it_approves_eligible_pending_sections_and_returns_count(): void
    {
        Queue::fake();
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $service = ChurchService::factory()->create([
            'date' => '2026-06-01',
            'service' => SermonService::Morning,
        ]);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $service->id,
            'extracted_date' => '2026-06-01',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Welcome->value,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
            'extracted_video_path' => 'sections/video.mp4',
            'extracted_audio_path' => 'sections/audio.mp3',
        ]);

        Storage::disk('public')->put('sections/video.mp4', 'video');
        Storage::disk('public')->put('sections/audio.mp3', 'audio');

        $result = $this->action->execute($service, $this->admin->id);

        $this->assertSame(1, $result['approved_count']);
        $this->assertSame([], $result['skipped_reasons']);
        $this->assertSame(ServiceSectionPublicationStatus::Approved, $section->fresh()->publication_status);

        Queue::assertPushed(PublishApprovedServiceSection::class);
    }

    #[Test]
    public function it_skips_sections_with_additional_review_flags_and_reports_reason(): void
    {
        Queue::fake();
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $service = ChurchService::factory()->create([
            'date' => '2026-06-02',
            'service' => SermonService::Morning,
        ]);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $service->id,
            'extracted_date' => '2026-06-02',
            'extracted_service' => SermonService::Morning->value,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Welcome->value,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
            'needs_manual_review' => true,
            'extracted_video_path' => 'sections/review/video.mp4',
            'extracted_audio_path' => 'sections/review/audio.mp3',
        ]);

        Storage::disk('public')->put('sections/review/video.mp4', 'video');
        Storage::disk('public')->put('sections/review/audio.mp3', 'audio');

        $result = $this->action->execute($service, $this->admin->id);

        $this->assertSame(0, $result['approved_count']);
        $this->assertArrayHasKey('blocked by other review flags', $result['skipped_reasons']);
        $this->assertSame(1, $result['skipped_reasons']['blocked by other review flags']);
    }

    #[Test]
    public function it_skips_sections_with_missing_media_and_reports_reason(): void
    {
        Queue::fake();
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $service = ChurchService::factory()->create([
            'date' => '2026-06-03',
            'service' => SermonService::Morning,
        ]);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $service->id,
            'extracted_date' => '2026-06-03',
            'extracted_service' => SermonService::Morning->value,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Welcome->value,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
            'needs_manual_review' => false,
            'extracted_video_path' => 'sections/missing/video.mp4',
            'extracted_audio_path' => 'sections/missing/audio.mp3',
        ]);

        $result = $this->action->execute($service, $this->admin->id);

        $this->assertSame(0, $result['approved_count']);
        $this->assertArrayHasKey('missing extracted media', $result['skipped_reasons']);
    }

    #[Test]
    public function it_only_approves_sections_belonging_to_the_selected_service(): void
    {
        Queue::fake();
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $selectedService = ChurchService::factory()->create([
            'date' => '2026-06-04',
            'service' => SermonService::Morning,
        ]);

        $otherService = ChurchService::factory()->create([
            'date' => '2026-06-04',
            'service' => SermonService::Evening,
        ]);

        $selectedRun = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $selectedService->id,
            'extracted_date' => '2026-06-04',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $otherRun = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $otherService->id,
            'extracted_date' => '2026-06-04',
            'extracted_service' => SermonService::Evening->value,
        ]);

        $selectedSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $selectedRun->id,
            'section_type' => ServiceSectionType::Welcome->value,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
            'extracted_video_path' => 'sections/selected/video.mp4',
            'extracted_audio_path' => 'sections/selected/audio.mp3',
        ]);

        $otherSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $otherRun->id,
            'section_type' => ServiceSectionType::Welcome->value,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
            'extracted_video_path' => 'sections/other/video.mp4',
            'extracted_audio_path' => 'sections/other/audio.mp3',
        ]);

        Storage::disk('public')->put('sections/selected/video.mp4', 'video');
        Storage::disk('public')->put('sections/selected/audio.mp3', 'audio');
        Storage::disk('public')->put('sections/other/video.mp4', 'video');
        Storage::disk('public')->put('sections/other/audio.mp3', 'audio');

        $result = $this->action->execute($selectedService, $this->admin->id);

        $this->assertSame(1, $result['approved_count']);
        $this->assertSame(ServiceSectionPublicationStatus::Approved, $selectedSection->fresh()->publication_status);
        $this->assertSame(ServiceSectionPublicationStatus::PendingApproval, $otherSection->fresh()->publication_status);
    }

    #[Test]
    public function it_records_batch_audit_metadata_with_shared_batch_id(): void
    {
        Queue::fake();
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $service = ChurchService::factory()->create([
            'date' => '2026-06-05',
            'service' => SermonService::Morning,
        ]);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $service->id,
            'extracted_date' => '2026-06-05',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $first = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Welcome->value,
            'section_order' => 1,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
            'extracted_video_path' => 'sections/audit/first/video.mp4',
            'extracted_audio_path' => 'sections/audit/first/audio.mp3',
        ]);

        $second = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Welcome->value,
            'section_order' => 2,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
            'extracted_video_path' => 'sections/audit/second/video.mp4',
            'extracted_audio_path' => 'sections/audit/second/audio.mp3',
        ]);

        Storage::disk('public')->put('sections/audit/first/video.mp4', 'video');
        Storage::disk('public')->put('sections/audit/first/audio.mp3', 'audio');
        Storage::disk('public')->put('sections/audit/second/video.mp4', 'video');
        Storage::disk('public')->put('sections/audit/second/audio.mp3', 'audio');

        $this->action->execute($service, $this->admin->id);

        $firstBatch = $first->fresh()->metadata['publication']['batch_approvals'][0] ?? null;
        $secondBatch = $second->fresh()->metadata['publication']['batch_approvals'][0] ?? null;

        $this->assertIsArray($firstBatch);
        $this->assertIsArray($secondBatch);
        $this->assertSame($firstBatch['batch_id'] ?? null, $secondBatch['batch_id'] ?? null);
        $this->assertSame($this->admin->id, $firstBatch['approved_by_user_id'] ?? null);
        $this->assertSame('service_review_dashboard', $firstBatch['source'] ?? null);
        $this->assertSame('approve_pending_publications', $firstBatch['action'] ?? null);
    }

    #[Test]
    public function it_returns_zero_approved_and_empty_skipped_when_no_pending_sections(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-06-06',
            'service' => SermonService::Morning,
        ]);

        $result = $this->action->execute($service, $this->admin->id);

        $this->assertSame(0, $result['approved_count']);
        $this->assertSame([], $result['skipped_reasons']);
    }

    #[Test]
    public function it_normalizes_unknown_approval_errors_to_lowercase_trimmed_string(): void
    {
        // The ApproveSectionForPublication action can return custom error strings.
        // The normalizeApprovalError default branch lowercases and trims any unrecognised message.
        // We verify the fallback by using a children's talk section that lacks a resolved speaker —
        // which produces the known "Choose a speaker..." message — but here we test the default
        // branch by mocking the action to return an unrecognised error.
        Queue::fake();
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $service = ChurchService::factory()->create([
            'date' => '2026-06-07',
            'service' => SermonService::Morning,
        ]);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $service->id,
            'extracted_date' => '2026-06-07',
            'extracted_service' => SermonService::Morning->value,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Welcome->value,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
            'needs_manual_review' => false,
            'extracted_video_path' => 'sections/default-error/video.mp4',
            'extracted_audio_path' => 'sections/default-error/audio.mp3',
        ]);

        Storage::disk('public')->put('sections/default-error/video.mp4', 'video');
        Storage::disk('public')->put('sections/default-error/audio.mp3', 'audio');

        $mockApprove = $this->createStub(ApproveSectionForPublication::class);
        $mockApprove->method('approvalBlocker')->willReturn(null);
        $mockApprove->method('execute')->willReturn('  Some Unexpected Error Message  ');
        $this->app->instance(ApproveSectionForPublication::class, $mockApprove);

        $action = app(BatchApproveServicePublications::class);
        $result = $action->execute($service, $this->admin->id);

        $this->assertSame(0, $result['approved_count']);
        $this->assertArrayHasKey('some unexpected error message', $result['skipped_reasons']);
    }
}
