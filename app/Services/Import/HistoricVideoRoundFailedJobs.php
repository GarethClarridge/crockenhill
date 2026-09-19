<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\ProcessingStatus;
use App\Enums\ServiceSectionPublicationStatus;
use App\Models\HistoricImportNestedJob;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\SongVideo;
use Carbon\CarbonImmutable;
use RuntimeException;

final class HistoricVideoRoundFailedJobs
{
    /** @param list<mixed> $reportedFailures */
    public function assertSuperseded(HistoricImportOperation $operation, array $reportedFailures): void
    {
        $storedFailures = $operation->nestedJobs()
            ->with('processingLog')
            ->where('state', 'failed')
            ->whereNotNull('settled_at')
            ->get()
            ->keyBy('job_key');
        $reportedKeys = [];
        foreach ($reportedFailures as $reportedFailure) {
            $jobKey = is_array($reportedFailure) ? ($reportedFailure['job_key'] ?? null) : null;
            $processingId = is_array($reportedFailure) ? ($reportedFailure['processing_id'] ?? null) : null;
            $settledAt = is_array($reportedFailure) ? ($reportedFailure['settled_at'] ?? null) : null;
            $stored = is_string($jobKey) ? $storedFailures->get($jobKey) : null;
            $reportedAttempts = is_array($reportedFailure) ? ($reportedFailure['attempts'] ?? null) : null;
            $processingLog = $stored?->processingLog;
            $sectionId = is_string($jobKey) && preg_match('/^auto-publish-section-(\d+)$/', $jobKey, $matches) === 1
                ? (int) $matches[1]
                : null;
            $section = $sectionId !== null ? ServiceSection::query()->find($sectionId) : null;
            $resolution = is_array($reportedFailure) ? ($reportedFailure['resolution'] ?? null) : null;

            if (! $stored instanceof HistoricImportNestedJob
                || ! is_string($processingId)
                || ! $processingLog instanceof MediaProcessingLog
                || $processingLog->processing_id !== $processingId
                || ! is_int($reportedAttempts)
                || $stored->attempts !== $reportedAttempts
                || ! is_string($stored->error_fingerprint)
                || ! is_string($settledAt)
                || $stored->settled_at === null
                || ! $stored->settled_at->equalTo(CarbonImmutable::parse($settledAt))
                || $processingLog->status !== ProcessingStatus::Completed
                || $processingLog->completed_at === null
                || $processingLog->completed_at->lt($stored->settled_at)
                || $resolution !== 'contained_not_superseded'
                || ! $section instanceof ServiceSection
                || $section->media_processing_log_id !== $processingLog->id
                || ! in_array($section->publication_status, [
                    ServiceSectionPublicationStatus::NotApplicable,
                    ServiceSectionPublicationStatus::PendingApproval,
                ], true)
                || ! is_string($section->asset_disk)
                || ! is_string($section->extracted_video_path)
                || SongVideo::query()->where('service_section_id', $section->id)->exists()) {
                throw new RuntimeException('Historic video round failed nested job is neither superseded nor durably contained.');
            }

            $reportedKeys[] = $jobKey;
        }

        $storedKeys = $storedFailures->keys()->all();
        sort($reportedKeys);
        sort($storedKeys);

        if ($reportedKeys !== $storedKeys) {
            throw new RuntimeException('Historic video round failed nested-job report does not match durable operation state.');
        }
    }
}
