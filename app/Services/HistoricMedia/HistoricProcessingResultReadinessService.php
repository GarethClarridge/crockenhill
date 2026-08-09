<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Data\HistoricProcessingResultReadiness;
use App\Enums\ProcessingStatus;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\SongVideo;
use Illuminate\Support\Facades\Bus;
use Throwable;

class HistoricProcessingResultReadinessService
{
    public function __construct(
        private readonly HistoricProcessingResultInventory $inventory,
    ) {}

    public function audit(MediaProcessingLog $processingLog, ?string $expectedLogicalHash = null): HistoricProcessingResultReadiness
    {
        $processingLog->loadMissing(['sermon', 'processingSteps', 'serviceSections']);
        $reasons = [];

        if ($processingLog->status !== ProcessingStatus::Completed) {
            $reasons[] = "Processing run is {$processingLog->status->value}, not completed.";
        }

        if ($processingLog->superseded_at !== null) {
            $reasons[] = 'Processing run has been superseded.';
        }

        $this->auditHistoricQueueState($processingLog, $reasons);

        if ($processingLog->processingSteps->contains(
            fn ($step): bool => in_array($step->status, [
                ProcessingStatus::Pending,
                ProcessingStatus::Started,
                ProcessingStatus::Processing,
                ProcessingStatus::Failed,
                ProcessingStatus::Cancelled,
            ], true),
        )) {
            $reasons[] = 'Processing history contains active or failed work.';
        }

        $sermon = $processingLog->sermon;

        if ($sermon === null) {
            $reasons[] = 'Main sermon publication is missing.';
        } else {
            foreach (['video_file_path', 'transcript_file_path', 'thumbnail_file_path'] as $attribute) {
                if (blank($sermon->getAttribute($attribute))) {
                    $reasons[] = "Main sermon {$attribute} is missing.";
                }
            }
        }

        $this->auditScripturePassageOutcomes($processingLog, $reasons);

        $artifacts = $processingLog->processing_metadata?->toArray()['service_artifacts'] ?? [];

        if (! is_array($artifacts) || $artifacts === []) {
            $reasons[] = 'Durable service artifact manifest is missing.';
        }

        foreach ($processingLog->serviceSections as $section) {
            $this->auditSection($section, $reasons);
        }

        $logicalHash = null;

        try {
            $logicalHash = $this->inventory->build($processingLog)['logical_hash'];
        } catch (Throwable $exception) {
            $reasons[] = $exception->getMessage();
        }

        if ($expectedLogicalHash !== null && $logicalHash !== $expectedLogicalHash) {
            $reasons[] = 'Durable processing output changed between readiness reads.';
        }

        return new HistoricProcessingResultReadiness(
            ready: $reasons === [],
            reasons: array_values(array_unique($reasons)),
            logicalHash: $logicalHash,
        );
    }

    /** @param list<string> $reasons */
    private function auditScripturePassageOutcomes(MediaProcessingLog $processingLog, array &$reasons): void
    {
        $metadata = $processingLog->processing_metadata?->toArray() ?? [];
        $historicImport = $metadata['historic_import'] ?? null;

        if (! is_array($historicImport) || ! is_string($historicImport['job_key'] ?? null)) {
            return;
        }

        $outcomes = is_array($historicImport['scripture_passage_outcomes'] ?? null)
            ? $historicImport['scripture_passage_outcomes']
            : [];
        $sermons = collect([$processingLog->sermon])
            ->merge($processingLog->serviceSections->pluck('publishedSermon'))
            ->filter()
            ->unique('id');

        foreach ($sermons as $publication) {
            if (blank($publication->reference) || $publication->scripture_passage_id !== null) {
                continue;
            }

            $outcome = $outcomes[$publication->slug] ?? null;
            $reason = is_array($outcome) ? ($outcome['reason'] ?? null) : null;

            if (! is_array($outcome)
                || ($outcome['status'] ?? null) !== 'approved_absent'
                || ! in_array($reason, ['api_disabled', 'budget_exhausted', 'not_found', 'source_has_no_passage'], true)) {
                $reasons[] = "Publication {$publication->slug} has unsettled Scripture Passage enrichment.";
            }
        }
    }

    /** @param list<string> $reasons */
    private function auditSection(ServiceSection $section, array &$reasons): void
    {
        if (in_array($section->publication_status, [
            ServiceSectionPublicationStatus::PendingApproval,
            ServiceSectionPublicationStatus::Approved,
        ], true)) {
            $reasons[] = "Section {$section->section_order} has an unsettled publication decision.";
        }

        if (
            in_array($section->section_type, [ServiceSectionType::Sermon, ServiceSectionType::ChildrensTalk], true)
            && $section->publication_status === ServiceSectionPublicationStatus::Published
            && $section->published_sermon_id === null
        ) {
            $reasons[] = "Section {$section->section_order} is published without its sermon record.";
        }

        if (
            $section->section_type === ServiceSectionType::Song
            && $section->publication_status === ServiceSectionPublicationStatus::Published
            && ! SongVideo::query()->where('service_section_id', $section->id)->exists()
        ) {
            $reasons[] = "Song section {$section->section_order} is published without a song video.";
        }
    }

    /** @param list<string> $reasons */
    private function auditHistoricQueueState(MediaProcessingLog $processingLog, array &$reasons): void
    {
        $metadata = $processingLog->processing_metadata?->toArray() ?? [];
        $historicImport = $metadata['historic_import'] ?? null;

        if (! is_array($historicImport) || ! is_string($historicImport['job_key'] ?? null)) {
            return;
        }

        $queue = $historicImport['queue'] ?? null;

        if (! is_array($queue)) {
            $reasons[] = 'Historic queue dispatch identity is missing.';

            return;
        }

        /**
         * This proves the chain was dispatched, not that it finished — the step
         * checks above are what prove completion. Both are needed: a run whose
         * chain was never dispatched has no failed steps to find.
         */
        if (! is_string($queue['main_chain_id'] ?? null) || ! is_string($queue['main_chain_dispatched_at'] ?? null)) {
            $reasons[] = 'Historic main processing chain was never dispatched.';
        }

        $fanOutBatchId = $queue['fan_out_batch_id'] ?? null;

        if (! is_string($fanOutBatchId) || $fanOutBatchId === '') {
            return;
        }

        $batch = Bus::findBatch($fanOutBatchId);

        if ($batch === null) {
            $reasons[] = 'Historic fan-out batch record is unavailable.';

            return;
        }

        if (! $batch->finished()) {
            $reasons[] = 'Historic fan-out work has not settled.';
        }

        if ($batch->cancelled()) {
            $reasons[] = 'Historic fan-out batch was cancelled.';
        }

        if ($batch->hasFailures()) {
            $reasons[] = 'Historic fan-out batch has failed jobs.';
        }
    }
}
