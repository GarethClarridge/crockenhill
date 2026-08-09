<?php

declare(strict_types=1);

namespace Tests\Integration\Services\HistoricMedia;

use App\Enums\ProcessingStatus;
use App\Enums\ServiceSectionPublicationStatus;
use App\Models\HistoricImportNestedJob;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\SermonProcessingStep;
use App\Models\ServiceSection;
use App\Services\HistoricMedia\HistoricProcessingResultReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class HistoricProcessingResultReadinessServiceTest extends TestCase
{
    use CreatesHistoricImportOperations;
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

    #[Test]
    public function historic_scripture_enrichment_requires_a_link_or_approved_terminal_absence(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'historic-scripture-sermon',
            'reference' => 'John 3:16',
            'scripture_passage_id' => null,
        ]);
        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'sermon_id' => $sermon->id,
            'processing_metadata' => [
                'historic_import' => ['job_key' => hash('sha256', 'scripture-item')],
            ],
        ]);
        $readiness = app(HistoricProcessingResultReadinessService::class);

        $pending = $readiness->audit($run);
        $this->assertContains(
            'Publication historic-scripture-sermon has unsettled Scripture Passage enrichment.',
            $pending->reasons,
        );

        $metadata = $run->processing_metadata->toArray();
        $metadata['historic_import']['scripture_passage_outcomes']['historic-scripture-sermon'] = [
            'status' => 'approved_absent',
            'reason' => 'api_disabled',
        ];
        $run->forceFill(['processing_metadata' => $metadata])->save();

        $settled = $readiness->audit($run->fresh());
        $this->assertNotContains(
            'Publication historic-scripture-sermon has unsettled Scripture Passage enrichment.',
            $settled->reasons,
        );
    }

    #[Test]
    public function nested_publication_jobs_must_be_terminal_complete_before_readiness(): void
    {
        $operation = $this->createHistoricImportOperation();
        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'historic_import_operation_id' => $operation->id,
        ]);
        $nested = HistoricImportNestedJob::query()->create([
            'historic_import_operation_id' => $operation->id,
            'media_processing_log_id' => $run->id,
            'job_key' => 'auto-publish-section-1',
            'job_type' => 'auto-publish',
            'state' => 'running',
            'attempts' => 1,
            'dispatched_at' => now(),
        ]);

        $pending = app(HistoricProcessingResultReadinessService::class)->audit($run);
        $this->assertContains(
            'Historic nested publication work is not terminal-complete: auto-publish-section-1.',
            $pending->reasons,
        );

        $nested->update(['state' => 'completed', 'settled_at' => now()]);
        $settled = app(HistoricProcessingResultReadinessService::class)->audit($run->fresh());
        $this->assertNotContains(
            'Historic nested publication work is not terminal-complete: auto-publish-section-1.',
            $settled->reasons,
        );
    }
}
