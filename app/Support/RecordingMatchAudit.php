<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ProcessingStatus;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use LogicException;

/**
 * @phpstan-type RecordingMatchFinding array{
 *     id: int,
 *     processing_id: string,
 *     status: string,
 *     filename: string,
 *     extracted_date: string,
 *     extracted_service: string,
 *     identity: string,
 *     identity_service_id: int|null,
 *     linked_service_id: int|null,
 *     linked_service_identity: string|null,
 *     duration_seconds: float|null,
 *     file_size: int|null,
 *     file_hash: string|null,
 *     section_count: int,
 *     owned_sermon_count: int,
 *     superseded: bool,
 *     created_at: string|null,
 *     signals: list<string>
 * }
 * @phpstan-type RecordingMatchGroup array{
 *     key: string,
 *     extracted_date?: string,
 *     extracted_service?: string,
 *     file_hash?: string,
 *     runs: list<RecordingMatchFinding>
 * }
 * @phpstan-type RecordingMatchReport array{
 *     summary: array{
 *         scanned_runs: int,
 *         latent_matches: int,
 *         identity_only_matches: int,
 *         identity_collision_groups: int,
 *         duplicate_hash_groups: int,
 *         suspicious_runs: int,
 *         link_mismatches: int,
 *         superseded_attachable_runs: int
 *     },
 *     latent_matches: list<RecordingMatchFinding>,
 *     identity_only_matches: list<RecordingMatchFinding>,
 *     identity_collisions: list<RecordingMatchGroup>,
 *     duplicate_hashes: list<RecordingMatchGroup>,
 *     suspicious_runs: list<RecordingMatchFinding>,
 *     link_mismatches: list<RecordingMatchFinding>,
 *     superseded_attachable_runs: list<RecordingMatchFinding>
 * }
 */
class RecordingMatchAudit
{
    public const ShortDurationSeconds = 300;

    /** @return RecordingMatchReport */
    public function report(): array
    {
        $runs = $this->runs();

        $latentMatches = array_values($runs
            ->filter(fn (array $run): bool => $this->isCompleted($run)
                && ! $run['superseded']
                && $run['identity_service_id'] === null)
            ->map(fn (array $run): array => $this->withSignal($run, 'latent_match'))
            ->all());

        $identityOnlyMatches = array_values($runs
            ->filter(fn (array $run): bool => ! $run['superseded']
                && $run['identity_service_id'] !== null
                && $run['linked_service_id'] === null)
            ->map(fn (array $run): array => $this->withSignal($run, 'identity_only_match'))
            ->all());

        $identityCollisions = $this->identityCollisions($runs);
        $duplicateHashes = $this->duplicateHashes($runs);

        $suspiciousRuns = array_values($runs
            ->filter(fn (array $run): bool => $run['signals'] !== [])
            ->all());

        $linkMismatches = array_values($runs
            ->filter(fn (array $run): bool => $this->linkMismatchesIdentity($run))
            ->map(fn (array $run): array => $this->withSignal($run, 'link_identity_mismatch'))
            ->all());

        $supersededAttachableRuns = array_values($runs
            ->filter(fn (array $run): bool => $this->isCompleted($run) && $run['superseded'])
            ->map(fn (array $run): array => $this->withSignal($run, 'superseded_but_attachable'))
            ->all());

        return [
            'summary' => [
                'scanned_runs' => $runs->count(),
                'latent_matches' => count($latentMatches),
                'identity_only_matches' => count($identityOnlyMatches),
                'identity_collision_groups' => count($identityCollisions),
                'duplicate_hash_groups' => count($duplicateHashes),
                'suspicious_runs' => count($suspiciousRuns),
                'link_mismatches' => count($linkMismatches),
                'superseded_attachable_runs' => count($supersededAttachableRuns),
            ],
            'latent_matches' => $latentMatches,
            'identity_only_matches' => $identityOnlyMatches,
            'identity_collisions' => $identityCollisions,
            'duplicate_hashes' => $duplicateHashes,
            'suspicious_runs' => $suspiciousRuns,
            'link_mismatches' => $linkMismatches,
            'superseded_attachable_runs' => $supersededAttachableRuns,
        ];
    }

    /** @param RecordingMatchReport $report */
    public function hasFindings(array $report): bool
    {
        return $report['latent_matches'] !== []
            || $report['identity_only_matches'] !== []
            || $report['identity_collisions'] !== []
            || $report['duplicate_hashes'] !== []
            || $report['suspicious_runs'] !== []
            || $report['link_mismatches'] !== []
            || $report['superseded_attachable_runs'] !== [];
    }

    /** @return Collection<int, RecordingMatchFinding> */
    private function runs(): Collection
    {
        $processingLogsTable = (new MediaProcessingLog)->getTable();
        $churchServicesTable = (new ChurchService)->getTable();
        $sermonsTable = (new Sermon)->getTable();

        /** @var EloquentCollection<int, MediaProcessingLog> $runs */
        $runs = MediaProcessingLog::query()
            ->livestream()
            ->whereNotNull('extracted_date')
            ->whereNotNull('extracted_service')
            ->select([
                "{$processingLogsTable}.id",
                "{$processingLogsTable}.processing_id",
                "{$processingLogsTable}.status",
                "{$processingLogsTable}.original_filename",
                "{$processingLogsTable}.extracted_date",
                "{$processingLogsTable}.extracted_service",
                "{$processingLogsTable}.church_service_id",
                "{$processingLogsTable}.duration",
                "{$processingLogsTable}.file_size",
                "{$processingLogsTable}.file_hash",
                "{$processingLogsTable}.sermon_id",
                "{$processingLogsTable}.audio_file_path",
                "{$processingLogsTable}.video_file_path",
                "{$processingLogsTable}.transcript_file_path",
                "{$processingLogsTable}.superseded_at",
                "{$processingLogsTable}.created_at",
            ])
            ->with('churchService:id,date,service')
            ->withCount('serviceSections')
            ->addSelect([
                'identity_service_id' => ChurchService::query()
                    ->select("{$churchServicesTable}.id")
                    ->whereColumn("{$churchServicesTable}.date", "{$processingLogsTable}.extracted_date")
                    ->whereColumn("{$churchServicesTable}.service", "{$processingLogsTable}.extracted_service")
                    ->limit(1),
                'owned_sermon_count' => Sermon::query()
                    ->selectRaw('COUNT(*)')
                    ->whereColumn("{$sermonsTable}.livestream_processing_id", "{$processingLogsTable}.processing_id"),
            ])
            ->orderBy("{$processingLogsTable}.id")
            ->get();

        /** @var Collection<int, RecordingMatchFinding> */
        return $runs->map(fn (MediaProcessingLog $run): array => $this->formatRun($run));
    }

    /** @return RecordingMatchFinding */
    private function formatRun(MediaProcessingLog $run): array
    {
        $date = $run->extracted_date?->toDateString() ?? '';
        $extractedService = $run->extracted_service;

        if ($extractedService === null) {
            throw new LogicException('Recording match audit query returned a run without an extracted service.');
        }

        $service = $extractedService->value;
        $linkedService = $run->churchService;
        $sectionCount = (int) $run->getAttribute('service_sections_count');
        $ownedSermonCount = (int) $run->getAttribute('owned_sermon_count');
        $signals = [];

        if ($run->duration !== null && $run->duration < self::ShortDurationSeconds) {
            $signals[] = 'short_duration';
        }

        if ($this->hasNoUsefulOutputs($run, $sectionCount, $ownedSermonCount)) {
            $signals[] = 'no_useful_outputs';
        }

        return [
            'id' => $run->id,
            'processing_id' => $run->processing_id,
            'status' => $run->status->value,
            'filename' => $run->original_filename,
            'extracted_date' => $date,
            'extracted_service' => $service,
            'identity' => trim("{$date} {$service}"),
            'identity_service_id' => $this->nullableInt($run->getAttribute('identity_service_id')),
            'linked_service_id' => $run->church_service_id,
            'linked_service_identity' => $linkedService instanceof ChurchService
                ? trim("{$linkedService->date->toDateString()} {$linkedService->service->value}")
                : null,
            'duration_seconds' => $run->duration,
            'file_size' => $run->file_size,
            'file_hash' => $run->file_hash,
            'section_count' => $sectionCount,
            'owned_sermon_count' => $ownedSermonCount,
            'superseded' => $run->superseded_at !== null,
            'created_at' => $run->created_at?->toIso8601String(),
            'signals' => $signals,
        ];
    }

    /**
     * @param  Collection<int, RecordingMatchFinding>  $runs
     * @return list<RecordingMatchGroup>
     */
    private function identityCollisions(Collection $runs): array
    {
        return array_values($runs
            ->filter(fn (array $run): bool => $this->isCompleted($run) && ! $run['superseded'])
            ->groupBy(fn (array $run): string => $run['identity'])
            ->filter(fn (Collection $group): bool => $group->count() > 1)
            ->map(function (Collection $group, string $identity): array {
                /** @var RecordingMatchFinding $first */
                $first = $group->first();

                return [
                    'key' => $identity,
                    'extracted_date' => $first['extracted_date'],
                    'extracted_service' => $first['extracted_service'],
                    'runs' => array_values($group
                        ->map(fn (array $run): array => $this->withSignal($run, 'identity_collision'))
                        ->all()),
                ];
            })
            ->sortBy('key')
            ->all());
    }

    /**
     * @param  Collection<int, RecordingMatchFinding>  $runs
     * @return list<RecordingMatchGroup>
     */
    private function duplicateHashes(Collection $runs): array
    {
        return array_values($runs
            ->filter(fn (array $run): bool => is_string($run['file_hash']) && $run['file_hash'] !== '')
            ->groupBy(fn (array $run): string => (string) $run['file_hash'])
            ->filter(fn (Collection $group): bool => $group->count() > 1)
            ->map(fn (Collection $group, string $hash): array => [
                'key' => $hash,
                'file_hash' => $hash,
                'runs' => array_values($group
                    ->map(fn (array $run): array => $this->withSignal($run, 'duplicate_file_hash'))
                    ->all()),
            ])
            ->sortBy('key')
            ->all());
    }

    /** @param RecordingMatchFinding $run */
    private function isCompleted(array $run): bool
    {
        return $run['status'] === ProcessingStatus::Completed->value;
    }

    /** @param RecordingMatchFinding $run */
    private function linkMismatchesIdentity(array $run): bool
    {
        if ($run['linked_service_id'] === null) {
            return false;
        }

        return $run['linked_service_identity'] !== $run['identity'];
    }

    private function hasNoUsefulOutputs(
        MediaProcessingLog $run,
        int $sectionCount,
        int $ownedSermonCount,
    ): bool {
        return $run->sermon_id === null
            && $sectionCount === 0
            && $ownedSermonCount === 0
            && blank($run->audio_file_path)
            && blank($run->video_file_path)
            && blank($run->transcript_file_path);
    }

    /**
     * @param  RecordingMatchFinding  $run
     * @return RecordingMatchFinding
     */
    private function withSignal(array $run, string $signal): array
    {
        $run['signals'] = array_values(array_unique([...$run['signals'], $signal]));

        return $run;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
