<?php

declare(strict_types=1);

namespace Tests\Integration\Services\HistoricMedia;

use App\Enums\ProcessingStatus;
use App\Enums\ServiceSectionPublicationStatus;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\SermonProcessingStep;
use App\Models\ServiceSection;
use App\Services\HistoricMedia\HistoricProcessingResultReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HistoricProcessingResultReadinessServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_settled_result_requires_two_identical_reads(): void
    {
        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/1/video.mp4',
            'transcript_file_path' => 'sermons/1/transcript.txt',
            'thumbnail_file_path' => 'sermons/1/thumbnail.webp',
        ]);
        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'sermon_id' => $sermon->id,
            'processing_metadata' => [
                'service_artifacts' => [
                    ['kind' => 'rms', 'path' => 'historic/run/rms.json', 'sha256' => str_repeat('a', 64)],
                ],
            ],
        ]);
        SermonProcessingStep::factory()->create([
            'processing_id' => $run->processing_id,
            'status' => ProcessingStatus::Completed,
        ]);

        $service = app(HistoricProcessingResultReadinessService::class);
        $first = $service->audit($run);
        $second = $service->audit($run->fresh(), $first->logicalHash);

        $this->assertTrue($first->ready, implode("\n", $first->reasons));
        $this->assertTrue($second->ready, implode("\n", $second->reasons));
        $this->assertSame($first->logicalHash, $second->logicalHash);
    }

    #[Test]
    public function it_reports_actionable_unsettled_reasons_and_hash_drift(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create([
            'status' => ProcessingStatus::Processing,
            'processing_metadata' => [],
        ]);
        SermonProcessingStep::factory()->create([
            'processing_id' => $run->processing_id,
            'status' => ProcessingStatus::Failed,
        ]);

        $result = app(HistoricProcessingResultReadinessService::class)->audit(
            $run,
            str_repeat('f', 64),
        );

        $this->assertFalse($result->ready);
        $this->assertContains('Processing run is processing, not completed.', $result->reasons);
        $this->assertContains('Processing history contains active or failed work.', $result->reasons);
        $this->assertContains('Main sermon publication is missing.', $result->reasons);
        $this->assertContains('Durable service artifact manifest is missing.', $result->reasons);
        $this->assertContains('Durable processing output changed between readiness reads.', $result->reasons);
    }

    #[Test]
    public function an_approval_required_section_must_have_a_final_disposition(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->completed()->create();
        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_order' => 1,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval,
            'source_segment_ids' => [],
        ]);

        $result = app(HistoricProcessingResultReadinessService::class)->audit($run);

        $this->assertContains('Section 1 has an unsettled publication decision.', $result->reasons);
    }

    #[Test]
    public function a_manifest_authorised_run_cannot_export_without_its_queue_identity(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'processing_metadata' => [
                'historic_import' => [
                    'job_key' => hash('sha256', 'manifest-item'),
                ],
            ],
        ]);

        $result = app(HistoricProcessingResultReadinessService::class)->audit($run);

        $this->assertContains('Historic queue dispatch identity is missing.', $result->reasons);
    }
}
