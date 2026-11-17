<?php

declare(strict_types=1);

namespace Tests\Feature\Actions\ServiceReview;

use App\Actions\Publication\ApproveSectionForPublication;
use App\Enums\ServiceSectionPublicationStatus;
use App\Jobs\AutoPublishServiceSection;
use App\Jobs\PublishApprovedServiceSection;
use App\Services\ChurchService\SectionPublication\SectionPublicationHandlerFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesAutomaticPublicationSong;
use Tests\TestCase;

/** Retained batch-action cases converted to the automatic route under G14. */
class BatchApproveServicePublicationsTest extends TestCase
{
    use CreatesAutomaticPublicationSong, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('public');
        Storage::fake('local');
        config(['media-processing.storage.sermon_disk' => 'public']);
    }

    #[Test]
    public function it_automatically_approves_a_clean_pending_section(): void
    {
        $section = $this->automaticSong();
        $this->advance($section->id);
        $this->assertSame(ServiceSectionPublicationStatus::Approved, $section->fresh()->publication_status);
        Queue::assertPushed(PublishApprovedServiceSection::class, 1);
    }

    #[Test]
    public function it_keeps_a_section_with_manual_review_pending(): void
    {
        $section = $this->automaticSong();
        $section->update(['needs_manual_review' => true]);
        $this->advance($section->id);
        $this->assertSame(ServiceSectionPublicationStatus::PendingApproval, $section->fresh()->publication_status);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_keeps_a_song_with_a_fresh_material_boundary_reason_pending(): void
    {
        $section = $this->automaticSong();
        $section->processingLog->update(['rms_log_path' => null]);
        $section->processingLog->putServiceTranscriptPath('test-automatic/missing.json');
        $this->advance($section->id);
        $this->assertSame(ServiceSectionPublicationStatus::PendingApproval, $section->fresh()->publication_status);
        $this->assertContains('song_boundary_evidence_unavailable', array_column($section->fresh()->metadata->raw['song_publication_review']['reasons'], 'kind'));
        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_keeps_a_section_with_genuinely_missing_media_pending(): void
    {
        $section = $this->automaticSong();
        Storage::disk('public')->delete($section->extracted_video_path);
        $this->advance($section->id);
        $this->assertSame(ServiceSectionPublicationStatus::PendingApproval, $section->fresh()->publication_status);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_advances_only_the_addressed_section(): void
    {
        $first = $this->automaticSong();
        $other = $this->automaticSong();
        $this->advance($first->id);
        $this->assertSame(ServiceSectionPublicationStatus::Approved, $first->fresh()->publication_status);
        $this->assertSame(ServiceSectionPublicationStatus::PendingApproval, $other->fresh()->publication_status);
        Queue::assertPushed(PublishApprovedServiceSection::class, 1);
    }

    #[Test]
    public function it_records_automatic_provenance_once_across_duplicate_jobs(): void
    {
        $section = $this->automaticSong();
        $this->advance($section->id);
        $this->advance($section->id);
        $audit = $section->fresh()->metadata->raw['publication']['batch_approvals'];
        $this->assertCount(1, $audit);
        $this->assertSame('automatic', $audit[0]['source']);
        $this->assertNull($audit[0]['approved_by_user_id']);
        $this->assertSame($section->classificationSignature(), $section->fresh()->metadata->raw['publication']['approved_signature']);
        Queue::assertPushed(PublishApprovedServiceSection::class, 1);
    }

    #[Test]
    public function it_does_nothing_when_the_addressed_section_is_missing(): void
    {
        $this->advance(9999999);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_rolls_back_an_automatic_approval_error_without_normalising_it(): void
    {
        $section = $this->automaticSong();
        $before = $section->metadata->toArray();
        $approval = $this->createMock(ApproveSectionForPublication::class);
        $approval->expects($this->once())->method('execute')->willReturn('  Some Unexpected Error Message  ');
        $this->app->instance(ApproveSectionForPublication::class, $approval);
        try {
            $this->advance($section->id);
            $this->fail('The approval failure must remain visible to job retry/failure handling.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('  Some Unexpected Error Message  ', $exception->getMessage());
        }
        $this->assertSame(ServiceSectionPublicationStatus::PendingApproval, $section->fresh()->publication_status);
        $this->assertSame($before, $section->fresh()->metadata->toArray());
        Queue::assertNothingPushed();
    }

    private function advance(int $id): void
    {
        (new AutoPublishServiceSection($id))->handle(app(SectionPublicationHandlerFactory::class));
    }
}
