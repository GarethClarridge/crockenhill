<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SchemaGuardrailAudit
{
    /**
     * @return array{
     *     speaker_profile_duplicates: array<int, array{preacher_id:int,provider:string,model_version:string,duplicate_count:int}>,
     *     speaker_sample_duplicates: array<int, array{speaker_profile_id:int,sermon_id:int,source:string,duplicate_count:int}>,
     *     orphaned_media_processing_logs: int,
     *     invalid_service_section_statuses: array<int, array{status:?string,row_count:int}>,
     *     invalid_service_section_publication_statuses: array<int, array{publication_status:?string,row_count:int}>,
     *     legacy_livestream_processing_log_rows: int
     * }
     */
    public function report(): array
    {
        return [
            'speaker_profile_duplicates' => $this->speakerProfileDuplicates(),
            'speaker_sample_duplicates' => $this->speakerSampleDuplicates(),
            'orphaned_media_processing_logs' => $this->orphanedMediaProcessingLogs(),
            'invalid_service_section_statuses' => $this->invalidServiceSectionStatuses(),
            'invalid_service_section_publication_statuses' => $this->invalidServiceSectionPublicationStatuses(),
            'legacy_livestream_processing_log_rows' => $this->legacyLivestreamProcessingLogRows(),
        ];
    }

    /**
     * @param  array{
     *     speaker_profile_duplicates: array<int, array{preacher_id:int,provider:string,model_version:string,duplicate_count:int}>,
     *     speaker_sample_duplicates: array<int, array{speaker_profile_id:int,sermon_id:int,source:string,duplicate_count:int}>,
     *     orphaned_media_processing_logs: int,
     *     invalid_service_section_statuses: array<int, array{status:?string,row_count:int}>,
     *     invalid_service_section_publication_statuses: array<int, array{publication_status:?string,row_count:int}>,
     *     legacy_livestream_processing_log_rows: int
     * }|null  $report
     */
    public function hasFailures(?array $report = null): bool
    {
        $report ??= $this->report();

        return $report['speaker_profile_duplicates'] !== []
            || $report['speaker_sample_duplicates'] !== []
            || $report['orphaned_media_processing_logs'] > 0
            || $report['invalid_service_section_statuses'] !== []
            || $report['invalid_service_section_publication_statuses'] !== []
            || $report['legacy_livestream_processing_log_rows'] > 0;
    }

    /**
     * @return array<int, array{preacher_id:int,provider:string,model_version:string,duplicate_count:int}>
     */
    public function speakerProfileDuplicates(): array
    {
        return DB::table('speaker_profiles')
            ->selectRaw('preacher_id, provider, model_version, COUNT(*) as duplicate_count')
            ->groupBy('preacher_id', 'provider', 'model_version')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('preacher_id')
            ->orderBy('provider')
            ->orderBy('model_version')
            ->get()
            ->map(fn (object $row): array => [
                'preacher_id' => (int) $row->preacher_id,
                'provider' => (string) $row->provider,
                'model_version' => (string) $row->model_version,
                'duplicate_count' => (int) $row->duplicate_count,
            ])
            ->all();
    }

    /**
     * @return array<int, array{speaker_profile_id:int,sermon_id:int,source:string,duplicate_count:int}>
     */
    public function speakerSampleDuplicates(): array
    {
        return DB::table('speaker_samples')
            ->whereNotNull('sermon_id')
            ->selectRaw('speaker_profile_id, sermon_id, source, COUNT(*) as duplicate_count')
            ->groupBy('speaker_profile_id', 'sermon_id', 'source')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('speaker_profile_id')
            ->orderBy('sermon_id')
            ->orderBy('source')
            ->get()
            ->map(fn (object $row): array => [
                'speaker_profile_id' => (int) $row->speaker_profile_id,
                'sermon_id' => (int) $row->sermon_id,
                'source' => (string) $row->source,
                'duplicate_count' => (int) $row->duplicate_count,
            ])
            ->all();
    }

    public function orphanedMediaProcessingLogs(): int
    {
        return DB::table('media_processing_logs')
            ->leftJoin('sermons', 'sermons.id', '=', 'media_processing_logs.sermon_id')
            ->whereNotNull('media_processing_logs.sermon_id')
            ->whereNull('sermons.id')
            ->count();
    }

    /**
     * @return array<int, array{status:?string,row_count:int}>
     */
    public function invalidServiceSectionStatuses(): array
    {
        $allowedStatuses = array_map(
            static fn (ServiceSectionStatus $status): string => $status->value,
            ServiceSectionStatus::cases()
        );

        return DB::table('service_sections')
            ->selectRaw('status, COUNT(*) as row_count')
            ->where(function ($query) use ($allowedStatuses): void {
                $query->whereNull('status')
                    ->orWhereNotIn('status', $allowedStatuses);
            })
            ->groupBy('status')
            ->orderBy('status')
            ->get()
            ->map(fn (object $row): array => [
                'status' => $row->status === null ? null : (string) $row->status,
                'row_count' => (int) $row->row_count,
            ])
            ->all();
    }

    /**
     * @return array<int, array{publication_status:?string,row_count:int}>
     */
    public function invalidServiceSectionPublicationStatuses(): array
    {
        $allowedStatuses = array_map(
            static fn (ServiceSectionPublicationStatus $status): string => $status->value,
            ServiceSectionPublicationStatus::cases()
        );

        return DB::table('service_sections')
            ->selectRaw('publication_status, COUNT(*) as row_count')
            ->where(function ($query) use ($allowedStatuses): void {
                $query->whereNull('publication_status')
                    ->orWhereNotIn('publication_status', $allowedStatuses);
            })
            ->groupBy('publication_status')
            ->orderBy('publication_status')
            ->get()
            ->map(fn (object $row): array => [
                'publication_status' => $row->publication_status === null ? null : (string) $row->publication_status,
                'row_count' => (int) $row->row_count,
            ])
            ->all();
    }

    public function legacyLivestreamProcessingLogRows(): int
    {
        if (! Schema::hasTable('livestream_processing_logs')) {
            return 0;
        }

        return DB::table('livestream_processing_logs')->count();
    }
}
