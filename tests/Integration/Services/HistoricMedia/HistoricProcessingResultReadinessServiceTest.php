<?php

declare(strict_types=1);

namespace Tests\Integration\Services\HistoricMedia;

use App\Enums\ProcessingStatus;
use App\Enums\ServiceSectionPublicationStatus;
use App\Jobs\StoreSermonVideo;
use App\Models\ChurchService;
use App\Models\HistoricImportNestedJob;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\SermonProcessingStep;
use App\Models\ServiceSection;
use App\Services\HistoricMedia\HistoricProcessingResultReadinessService;
use App\Services\Sermon\SermonExposurePolicy;
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

    /**
     * Ruling P2: thumbnails wait for the release membership's editorial QA, and GenerateThumbnail
     * skips any sermon whose video may not be shown. The 406 deferred thumbnails were counted as
     * missing work; a thumbnail the policy would make is still required.
     */
    #[Test]
    public function a_thumbnail_deferred_until_release_is_pending_not_missing(): void
    {
        $quarantined = Sermon::factory()->create([
            'video_file_path' => 'sermons/2/video.mp4',
            'transcript_file_path' => 'sermons/2/transcript.txt',
            'thumbnail_file_path' => null,
            'publication_state' => 'quarantined',
        ]);
        $run = MediaProcessingLog::factory()->livestream()->completed()->create(['sermon_id' => $quarantined->id]);
        $service = app(HistoricProcessingResultReadinessService::class);

        $this->assertNotContains('Main sermon thumbnail_file_path is missing.', $service->audit($run)->reasons);

        $public = Sermon::factory()->create([
            'video_file_path' => 'sermons/3/video.mp4',
            'transcript_file_path' => 'sermons/3/transcript.txt',
            'thumbnail_file_path' => null,
        ]);
        $this->assertTrue(app(SermonExposurePolicy::class)->shouldGenerateVideoThumbnail($public));
        $publicRun = MediaProcessingLog::factory()->livestream()->completed()->create(['sermon_id' => $public->id]);

        $this->assertContains('Main sermon thumbnail_file_path is missing.', $service->audit($publicRun)->reasons);
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

    /**
     * 894 and 1126 (operator, 2026-10-15): the fuller recording wins the service and adopts the
     * better recording's sermon. The holder is superseded yet still exports its sermon, and the
     * adopting run, which published none, is not short of one.
     */
    #[Test]
    public function an_adopted_sermon_keeps_both_recordings_exportable(): void
    {
        $service = ChurchService::factory()->create();
        $holder = MediaProcessingLog::factory()->livestream()->completed()->create(['church_service_id' => $service->id]);
        $sermon = Sermon::factory()->create(['livestream_processing_id' => $holder->processing_id]);
        $holder->forceFill(['sermon_id' => $sermon->id])->save();
        $adopting = MediaProcessingLog::factory()->livestream()->completed()->create([
            'church_service_id' => $service->id,
            'processing_metadata' => ['sermon_adopted' => ['sermon_id' => $sermon->id, 'holder_processing_id' => $holder->processing_id]],
        ]);
        $holder->forceFill(['superseded_at' => now(), 'superseded_by_processing_log_id' => $adopting->id])->save();

        $readiness = app(HistoricProcessingResultReadinessService::class);

        $this->assertNotContains('Processing run has been superseded.', $readiness->audit($holder->fresh())->reasons);
        $this->assertNotContains('Main sermon publication is missing.', $readiness->audit($adopting->fresh())->reasons);
    }

    /** An excluded run (a rehearsal, a private occasion) is never exported, whatever its metadata holds. */
    #[Test]
    public function an_excluded_run_is_not_exported(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'processing_metadata' => ['exclusion' => ['reason' => 'private_occasion', 'evidence' => ['note' => 'funeral']]],
        ]);

        $result = app(HistoricProcessingResultReadinessService::class)->audit($run);

        $this->assertFalse($result->ready);
        $this->assertSame(['Processing run is excluded (private_occasion); it is not exported.'], $result->reasons);
    }

    /**
     * A retired run is withdrawn like an excluded one: 937, a dead duplicate of 938 (register X1),
     * was counted as "never dispatched" work still owed.
     */
    #[Test]
    public function a_superseded_run_whose_sermon_nothing_adopted_is_retired_and_not_exported(): void
    {
        $service = ChurchService::factory()->create();
        $winner = MediaProcessingLog::factory()->livestream()->completed()->create(['church_service_id' => $service->id]);
        $loser = MediaProcessingLog::factory()->livestream()->completed()->create([
            'church_service_id' => $service->id,
            'superseded_at' => now(),
            'superseded_by_processing_log_id' => $winner->id,
        ]);

        $loser->forceFill(['status' => 'failed'])->save();

        $result = app(HistoricProcessingResultReadinessService::class)->audit($loser->fresh());

        $this->assertFalse($result->ready);
        $this->assertSame(["Processing run is retired (superseded by run {$winner->id}); it is not exported."], $result->reasons);
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
            'Historic nested publication work is not terminal-complete: auto-publish-section-1 (state: running).',
            $pending->reasons,
        );

        $nested->update(['state' => 'completed', 'settled_at' => now()]);
        $settled = app(HistoricProcessingResultReadinessService::class)->audit($run->fresh());
        $this->assertNotContains(
            'Historic nested publication work is not terminal-complete: auto-publish-section-1 (state: running).',
            $settled->reasons,
        );
    }

    #[Test]
    public function historic_video_storage_readiness_reports_the_nested_job_state(): void
    {
        $operation = $this->createHistoricImportOperation();
        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'historic_import_operation_id' => $operation->id,
        ]);
        HistoricImportNestedJob::query()->create([
            'historic_import_operation_id' => $operation->id,
            'media_processing_log_id' => $run->id,
            'job_key' => StoreSermonVideo::nestedJobKey($run->processing_id),
            'job_type' => StoreSermonVideo::class,
            'state' => 'retryable',
            'attempts' => 1,
            'dispatched_at' => now(),
        ]);

        $result = app(HistoricProcessingResultReadinessService::class)->audit($run);

        $this->assertContains(
            'Historic sermon video storage is not terminal-complete: '.StoreSermonVideo::nestedJobKey($run->processing_id).' (state: retryable).',
            $result->reasons,
        );
    }

    #[Test]
    public function artifacts_under_the_unresolved_date_fallback_are_not_settled(): void
    {
        $run = $this->runWithArtifactPath('service-transcripts/unknown-date/morning-abc123.raw.json');

        $result = app(HistoricProcessingResultReadinessService::class)->audit($run);

        $this->assertFalse($result->ready);
        $this->assertContains(
            'Durable service artifacts are stored under the unresolved-date fallback.',
            $result->reasons,
        );
    }

    #[Test]
    public function artifacts_foldered_under_a_real_date_stay_settled(): void
    {
        $run = $this->runWithArtifactPath('service-transcripts/2020-03-22/morning-abc123.raw.json');

        $result = app(HistoricProcessingResultReadinessService::class)->audit($run);

        $this->assertTrue($result->ready, implode("\n", $result->reasons));
    }

    /**
     * An otherwise-settled run carrying one durable artifact at the given path, so a
     * readiness failure isolates the foldering check rather than any other reason.
     */
    private function runWithArtifactPath(string $path): MediaProcessingLog
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
                    ['kind' => 'raw', 'path' => $path, 'sha256' => str_repeat('a', 64)],
                ],
            ],
        ]);
        SermonProcessingStep::factory()->create([
            'processing_id' => $run->processing_id,
            'status' => ProcessingStatus::Completed,
        ]);

        return $run;
    }
}
