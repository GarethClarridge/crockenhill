<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Publication;

use App\Actions\Publication\ExpireSectionPublicationAssets;
use App\Enums\ServiceSectionPublicationStatus;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExpireSectionPublicationAssetsTest extends TestCase
{
    use RefreshDatabase;

    private ExpireSectionPublicationAssets $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(ExpireSectionPublicationAssets::class);
    }

    #[Test]
    public function it_transitions_pending_section_to_not_applicable_and_clears_asset_fields(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'publication_status' => ServiceSectionPublicationStatus::PENDING_APPROVAL->value,
            'extracted_video_path' => 'sermons/sections/40/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-40.mp3',
            'extracted_at' => now()->subHours(72),
            'unpublished_expires_at' => now()->subHour(),
        ]);

        $this->action->execute($section, ['reason' => 'asset_expiry', 'cleaned_by' => 'scheduler']);

        $section->refresh();
        $this->assertSame(ServiceSectionPublicationStatus::NOT_APPLICABLE, $section->publication_status);
        $this->assertNull($section->extracted_video_path);
        $this->assertNull($section->extracted_audio_path);
        $this->assertNull($section->extracted_at);
        $this->assertNull($section->unpublished_expires_at);
    }

    #[Test]
    public function it_writes_audit_metadata_with_caller_context_and_cleaned_at(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'publication_status' => ServiceSectionPublicationStatus::APPROVED->value,
            'extracted_at' => now()->subHours(24),
            'unpublished_expires_at' => now()->subHour(),
        ]);

        $this->action->execute($section, ['reason' => 'asset_expiry', 'cleaned_by' => 'scheduler']);

        $section->refresh();
        $cleanup = $section->metadataData()->toArray()['cleanup'] ?? null;
        $this->assertIsArray($cleanup);
        $this->assertSame('asset_expiry', $cleanup['reason']);
        $this->assertSame('scheduler', $cleanup['cleaned_by']);
        $this->assertSame(ServiceSectionPublicationStatus::APPROVED->value, $cleanup['previous_status']);
        $this->assertArrayHasKey('cleaned_at', $cleanup);
    }

    #[Test]
    public function it_does_not_delete_files_itself(): void
    {
        Storage::spy();

        $run = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'publication_status' => ServiceSectionPublicationStatus::PENDING_APPROVAL->value,
            'extracted_video_path' => 'sermons/sections/41/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-41.mp3',
        ]);

        $this->action->execute($section, ['reason' => 'test']);

        Storage::shouldNotHaveReceived('delete');

        $section->refresh();
        $this->assertSame(ServiceSectionPublicationStatus::NOT_APPLICABLE, $section->publication_status);
    }

    #[Test]
    public function it_handles_idempotent_transition_when_section_is_already_not_applicable(): void
    {
        // All states allow NOT_APPLICABLE (either in their allowed list, or via same-state guard).
        // A section already in NOT_APPLICABLE should succeed since transition returns true for same-state.
        $run = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
        ]);

        $this->action->execute($section, ['reason' => 'idempotent_retry', 'cleaned_by' => 'scheduler']);

        $section->refresh();
        $this->assertSame(ServiceSectionPublicationStatus::NOT_APPLICABLE, $section->publication_status);
        $this->assertSame('idempotent_retry', $section->metadata['cleanup']['reason'] ?? null);
    }
}
