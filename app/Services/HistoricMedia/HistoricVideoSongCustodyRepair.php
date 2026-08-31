<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Data\HistoricStagingContext;
use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\SermonPublicationState;
use App\Enums\ServiceSectionPublicationStatus;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\SongVideo;
use App\Support\Path;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Validate and repair historic song-video custody for an exact set of runs.
 *
 * The canary predates the direct-lane custody binding, so its song rows can be
 * published records with no operation or disk owner even though their bytes
 * are still on historic staging. Inspection is read-only and exact-membership;
 * apply first makes every selected row private and operation-bound, then uses
 * the normal create-only promotion path. Review-held section candidates are
 * reported and retained on staging for the M9 review workflow.
 *
 * Deletion trigger: Delete after IC8 closeout once the song custody repair and
 * its retained evidence are complete.
 */
final class HistoricVideoSongCustodyRepair
{
    /**
     * @param  list<string>  $processingIds
     * @return list<array{
     *     processing_id: string,
     *     log: MediaProcessingLog,
     *     song_videos: list<SongVideo>,
     *     pending_song_videos: list<SongVideo>,
     *     held_candidates: list<ServiceSection>,
     *     disposition: 'pending'|'already_repaired'|'retained_for_review',
     *     asset_count: int,
     *     held_candidate_count: int,
     *     staged_bytes: int,
     *     held_bytes: int
     * }>
     */
    public function inspect(HistoricImportOperation $operation, array $processingIds): array
    {
        if ($processingIds === []) {
            throw new RuntimeException('At least one exact completed historic --processing-id is required.');
        }

        if (count($processingIds) !== count(array_unique($processingIds))) {
            throw new RuntimeException('Each historic --processing-id must be listed exactly once.');
        }

        $this->staging->assertLocalProcessingIsIsolated();

        $runs = MediaProcessingLog::query()
            ->whereIn('processing_id', $processingIds)
            ->get()
            ->keyBy('processing_id');
        $missing = array_values(array_diff($processingIds, $runs->keys()->all()));

        if ($missing !== []) {
            throw new RuntimeException(
                'Every selected processing ID must exist; missing: '.implode(', ', $missing).'.'
            );
        }

        $context = $this->stagingContextForRuns($operation, $runs->values());

        return $this->stagingContextRegistry->within(
            $context,
            function () use ($operation, $processingIds, $runs): array {
                $entries = [];

                foreach ($processingIds as $processingId) {
                    $run = $runs->get($processingId);

                    if (! $run instanceof MediaProcessingLog) {
                        throw new RuntimeException("Processing run {$processingId} could not be loaded.");
                    }

                    $this->assertRunBelongsToOperation($run, $operation);
                    $songVideos = $this->songVideosForRun($run);
                    $heldCandidates = $this->heldCandidatesForRun($run);

                    if ($songVideos === [] && $heldCandidates === []) {
                        throw new RuntimeException(
                            "Processing run {$processingId} has no historic song video or held candidate to repair."
                        );
                    }

                    $states = [];
                    foreach ($songVideos as $songVideo) {
                        $states[$songVideo->id] = $this->assetState($songVideo, $operation);
                    }

                    $pendingSongVideos = array_values(array_filter(
                        $songVideos,
                        fn (SongVideo $songVideo): bool => ! $this->isAlreadyRepaired(
                            $songVideo,
                            $states[$songVideo->id],
                            $operation,
                        ),
                    ));
                    $heldBytes = $this->heldCandidateBytes($heldCandidates);

                    $entries[] = [
                        'processing_id' => $processingId,
                        'log' => $run,
                        'song_videos' => $songVideos,
                        'pending_song_videos' => $pendingSongVideos,
                        'held_candidates' => $heldCandidates,
                        'disposition' => $songVideos === []
                            ? 'retained_for_review'
                            : ($pendingSongVideos === [] ? 'already_repaired' : 'pending'),
                        'asset_count' => count($songVideos),
                        'held_candidate_count' => count($heldCandidates),
                        'staged_bytes' => array_sum(array_map(
                            static fn (array $state): int => $state['staged_bytes'],
                            $states,
                        )),
                        'held_bytes' => $heldBytes,
                    ];
                }

                return $entries;
            },
        );
    }

    /**
     * Make pending rows private and operation-bound before byte promotion. A
     * failed copy therefore leaves a quarantined row and its verified staging
     * source for a later exact retry.
     *
     * @param  list<array{
     *     processing_id: string,
     *     log: MediaProcessingLog,
     *     song_videos: list<SongVideo>,
     *     pending_song_videos: list<SongVideo>,
     *     held_candidates: list<ServiceSection>,
     *     disposition: 'pending'|'already_repaired'|'retained_for_review',
     *     asset_count: int,
     *     held_candidate_count: int,
     *     staged_bytes: int,
     *     held_bytes: int
     * }>  $entries
     * @return array{
     *     repaired: int,
     *     already_repaired: int,
     *     held_candidates: int,
     *     held_section_candidates_promoted: int,
     *     assets_promoted: int,
     *     assets_already_promoted: int,
     *     promoted_bytes: int,
     *     reclaimed_bytes: int
     * }
     */
    public function apply(HistoricImportOperation $operation, array $entries): array
    {
        $runs = new Collection(array_map(
            static fn (array $entry): MediaProcessingLog => $entry['log'],
            $entries,
        ));
        $context = $this->stagingContextForRuns($operation, $runs);

        return $this->stagingContextRegistry->within(
            $context,
            fn (): array => $this->applyWithinStagingContext($operation, $entries),
        );
    }

    /**
     * @param  list<array{
     *     processing_id: string,
     *     log: MediaProcessingLog,
     *     song_videos: list<SongVideo>,
     *     pending_song_videos: list<SongVideo>,
     *     held_candidates: list<ServiceSection>,
     *     disposition: 'pending'|'already_repaired'|'retained_for_review',
     *     asset_count: int,
     *     held_candidate_count: int,
     *     staged_bytes: int,
     *     held_bytes: int
     * }>  $entries
     * @return array{
     *     repaired: int,
     *     already_repaired: int,
     *     held_candidates: int,
     *     held_section_candidates_promoted: int,
     *     assets_promoted: int,
     *     assets_already_promoted: int,
     *     promoted_bytes: int,
     *     reclaimed_bytes: int
     * }
     */
    private function applyWithinStagingContext(HistoricImportOperation $operation, array $entries): array
    {
        $pendingEntries = array_values(array_filter(
            $entries,
            static fn (array $entry): bool => $entry['pending_song_videos'] !== [],
        ));
        $pendingVideos = [];

        foreach ($pendingEntries as $entry) {
            foreach ($entry['pending_song_videos'] as $songVideo) {
                $pendingVideos[$songVideo->id] = $songVideo;
            }
        }

        DB::transaction(function () use ($operation, $pendingVideos): void {
            $staging = $this->staging->stagingDisk();
            $quarantine = $this->transfer->targetDiskName();

            foreach ($pendingVideos as $songVideo) {
                $locked = SongVideo::query()->lockForUpdate()->find($songVideo->id);

                if (! $locked instanceof SongVideo) {
                    throw new RuntimeException("Song video {$songVideo->id} disappeared before custody repair.");
                }

                $this->assertCanQuarantine($locked, $operation, $staging, $quarantine);
                $assetDisk = $locked->asset_disk;

                if ($assetDisk === null) {
                    $assetDisk = $staging;
                }

                $locked->forceFill([
                    'publication_state' => SermonPublicationState::Quarantined,
                    'asset_disk' => $assetDisk,
                    'historic_import_operation_id' => $operation->id,
                ])->save();
            }
        });

        $totals = [
            'repaired' => count($pendingVideos),
            'already_repaired' => array_sum(array_map(
                static fn (array $entry): int => count($entry['song_videos']) - count($entry['pending_song_videos']),
                $entries,
            )),
            'held_candidates' => array_sum(array_column($entries, 'held_candidate_count')),
            'held_section_candidates_promoted' => 0,
            'assets_promoted' => 0,
            'assets_already_promoted' => 0,
            'promoted_bytes' => 0,
            'reclaimed_bytes' => 0,
        ];

        /**
         * A run can hold review-held candidate media without owning a single
         * unpromoted song video, so selecting on pending song videos alone would
         * leave exactly the held clips this repair exists to give custody to.
         */
        $promotableEntries = array_values(array_filter(
            $entries,
            static fn (array $entry): bool => $entry['pending_song_videos'] !== []
                || $entry['held_candidate_count'] > 0,
        ));

        foreach ($promotableEntries as $entry) {
            $run = $entry['log']->fresh();

            if (! $run instanceof MediaProcessingLog) {
                throw new RuntimeException("Processing run {$entry['processing_id']} disappeared during custody repair.");
            }

            $this->assertRunBelongsToOperation($run, $operation);
            $result = $this->promotion->promoteSongVideos($run);
            $totals['held_section_candidates_promoted'] += $result['held_section_candidates'];
            $totals['assets_promoted'] += $result['assets_promoted'];
            $totals['assets_already_promoted'] += $result['assets_already_promoted'];
            $totals['promoted_bytes'] += $result['promoted_bytes'];
            $totals['reclaimed_bytes'] += $result['reclaimed_bytes'];
        }

        return $totals;
    }

    public function __construct(
        private readonly HistoricAssetPromotion $promotion,
        private readonly HistoricProcessingResultAssetTransfer $transfer,
        private readonly HistoricStagingGuard $staging,
        private readonly HistoricStagingContextRegistry $stagingContextRegistry,
    ) {}

    /**
     * @param  Collection<int, MediaProcessingLog>  $runs
     */
    private function stagingContextForRuns(
        HistoricImportOperation $operation,
        Collection $runs,
    ): HistoricStagingContext {
        $contexts = $runs
            ->map(static fn (MediaProcessingLog $run): ?HistoricStagingContext => $run->historicStagingContext());

        if ($contexts->contains(null)) {
            throw new RuntimeException('Every selected song run must record its approved historic staging context.');
        }

        $context = $contexts->first();

        if (! $context instanceof HistoricStagingContext) {
            throw new RuntimeException('The selected song runs have no historic staging context.');
        }

        foreach ($contexts as $other) {
            if (! $other instanceof HistoricStagingContext || $other->toArray() !== $context->toArray()) {
                throw new RuntimeException('Every selected song run must use the same historic staging context.');
            }
        }

        $manifestHash = $operation->manifest_hashes['historic_video']
            ?? $operation->manifest_hashes['video']
            ?? null;

        if (! is_string($manifestHash)
            || ! hash_equals($manifestHash, $context->manifestHash)
            || ! hash_equals($operation->plan_hash, $context->planHash)) {
            throw new RuntimeException('The song staging context does not match the named operation manifest and plan.');
        }

        return $context;
    }

    private function assertRunBelongsToOperation(
        MediaProcessingLog $run,
        HistoricImportOperation $operation,
    ): void {
        if ($run->status !== ProcessingStatus::Completed) {
            throw new RuntimeException("Processing run {$run->processing_id} must be completed.");
        }

        if ($run->processing_type !== MediaType::Livestream) {
            throw new RuntimeException("Processing run {$run->processing_id} is not a historic livestream run.");
        }

        if ($run->historic_import_operation_id !== $operation->id) {
            throw new RuntimeException(
                "Processing run {$run->processing_id} does not belong to the named historic operation."
            );
        }

        $metadata = $run->processing_metadata?->toArray() ?? [];
        $historicImport = $metadata['historic_import'] ?? null;
        $recordedOperationId = is_array($historicImport) ? ($historicImport['operation_id'] ?? null) : null;

        if (! is_array($historicImport) || ! is_string($run->historicImportJobKey())) {
            throw new RuntimeException(
                "Processing run {$run->processing_id} is not an operation-bound historic run."
            );
        }

        if ($recordedOperationId !== $operation->operation_id) {
            throw new RuntimeException(
                "Processing run {$run->processing_id} metadata operation identity does not match the named historic operation."
            );
        }
    }

    /** @return list<SongVideo> */
    private function songVideosForRun(MediaProcessingLog $run): array
    {
        return array_values(SongVideo::query()
            ->whereHas('serviceSection', function (Builder $query) use ($run): void {
                $query->where('media_processing_log_id', $run->id);
            })
            ->orderBy('id')
            ->get()
            ->all());
    }

    /** @return list<ServiceSection> */
    private function heldCandidatesForRun(MediaProcessingLog $run): array
    {
        $sections = array_values(ServiceSection::query()
            ->where('media_processing_log_id', $run->id)
            ->where('publication_status', ServiceSectionPublicationStatus::PendingApproval->value)
            ->where(function (Builder $query): void {
                $query->whereNotNull('extracted_video_path')
                    ->orWhereNotNull('extracted_audio_path');
            })
            ->orderBy('id')
            ->get()
            ->all());

        $staging = Storage::disk($this->staging->stagingDisk());
        $quarantine = Storage::disk($this->transfer->targetDiskName());

        /**
         * Quarantine counts as present. A candidate promoted by an earlier run of
         * this repair has legitimately left staging, and demanding it be there
         * would make the second invocation fail on its own successful work.
         */
        foreach ($sections as $section) {
            foreach ([$section->extracted_video_path, $section->extracted_audio_path] as $path) {
                if (! is_string($path) || $path === '') {
                    continue;
                }

                if (Path::isUnsafe($path) || (! $staging->exists($path) && ! $quarantine->exists($path))) {
                    throw new RuntimeException(
                        "Held section {$section->id} candidate {$path} is missing from historic staging and quarantine."
                    );
                }
            }
        }

        return $sections;
    }

    /**
     * @return array{staged_bytes: int, staged_count: int, quarantine_count: int}
     */
    private function assetState(SongVideo $songVideo, HistoricImportOperation $operation): array
    {
        $path = trim($songVideo->video_file_path);

        if ($path === '' || Path::isUnsafe($path)) {
            throw new RuntimeException("Song video {$songVideo->id} has an unsafe or empty asset path.");
        }

        $staging = Storage::disk($this->staging->stagingDisk());
        $quarantine = Storage::disk($this->transfer->targetDiskName());
        $onStaging = $staging->exists($path);
        $onQuarantine = $quarantine->exists($path);

        if (! $onStaging && ! $onQuarantine) {
            throw new RuntimeException(
                "Song video {$songVideo->id} asset {$path} is missing from historic staging and quarantine."
            );
        }

        if ($songVideo->historic_import_operation_id !== null
            && $songVideo->historic_import_operation_id !== $operation->id) {
            throw new RuntimeException("Song video {$songVideo->id} is already owned by a different historic operation.");
        }

        $allowedDisks = [$this->staging->stagingDisk(), $this->transfer->targetDiskName()];

        if ($songVideo->asset_disk !== null && ! in_array($songVideo->asset_disk, $allowedDisks, true)) {
            throw new RuntimeException("Song video {$songVideo->id} is already owned by disk {$songVideo->asset_disk}.");
        }

        if ($songVideo->publication_state === SermonPublicationState::Published && ! $onStaging) {
            throw new RuntimeException(
                "Song video {$songVideo->id} has quarantine bytes without a complete staging source; refusing an unverifiable custody repair."
            );
        }

        return [
            'staged_bytes' => $onStaging ? $staging->size($path) : 0,
            'staged_count' => $onStaging ? 1 : 0,
            'quarantine_count' => $onQuarantine ? 1 : 0,
        ];
    }

    /**
     * @param  array{staged_bytes: int, staged_count: int, quarantine_count: int}  $state
     */
    private function isAlreadyRepaired(
        SongVideo $songVideo,
        array $state,
        HistoricImportOperation $operation,
    ): bool {
        return $songVideo->publication_state === SermonPublicationState::Quarantined
            && $songVideo->asset_disk === $this->transfer->targetDiskName()
            && $songVideo->historic_import_operation_id === $operation->id
            && $state['staged_count'] === 0;
    }

    private function assertCanQuarantine(
        SongVideo $songVideo,
        HistoricImportOperation $operation,
        string $staging,
        string $quarantine,
    ): void {
        if ($songVideo->historic_import_operation_id !== null
            && $songVideo->historic_import_operation_id !== $operation->id) {
            throw new RuntimeException("Song video {$songVideo->id} is already owned by a different historic operation.");
        }

        if ($songVideo->asset_disk !== null && ! in_array($songVideo->asset_disk, [$staging, $quarantine], true)) {
            throw new RuntimeException("Song video {$songVideo->id} is already owned by disk {$songVideo->asset_disk}.");
        }

        if ($songVideo->publication_state === SermonPublicationState::Published
            && $songVideo->asset_disk !== null) {
            throw new RuntimeException(
                "Song video {$songVideo->id} is published with an existing disk owner; refusing an inconsistent custody repair."
            );
        }
    }

    /**
     * Held-candidate bytes *still occupying the working volume*.
     *
     * A candidate already promoted into quarantine contributes nothing here: it
     * no longer competes for the staging capacity this measure exists to report,
     * and reading its size from a disk it has left would make an idempotent
     * replay throw. A path on neither disk is left to the promotion step, which
     * fails closed on it by name.
     *
     * @param  list<ServiceSection>  $sections
     */
    private function heldCandidateBytes(array $sections): int
    {
        $staging = Storage::disk($this->staging->stagingDisk());
        $paths = [];
        $bytes = 0;

        foreach ($sections as $section) {
            foreach ([$section->extracted_video_path, $section->extracted_audio_path] as $path) {
                if (! is_string($path) || $path === '' || isset($paths[$path])) {
                    continue;
                }

                $paths[$path] = true;

                if ($staging->exists($path)) {
                    $bytes += $staging->size($path);
                }
            }
        }

        return $bytes;
    }
}
