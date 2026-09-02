<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Enums\SermonPublicationState;
use App\Enums\ServiceSectionPublicationStatus;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Models\SongVideo;
use App\Services\Import\HistoricSermonPublicationService;
use App\Services\Sermon\SermonPromotionAssets;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Promote a historic run's durable outputs from working staging to the private
 * quarantine disk (plan §Phase 5, items 2, 3 and 7).
 *
 * Until this existed the direct processing lane simply left everything on
 * staging. Nothing set `sermons.asset_disk`, so the column the whole quarantine
 * model reads was null, and the records were created in the default `published`
 * state pointing at paths on a removable working volume. The bundle-import lane
 * had the opposite behaviour all along — {@see HistoricMediaGraphPersister}
 * creates its sermons `Quarantined` with `asset_disk` set to the quarantine disk
 * — so this closes the gap rather than inventing a second convention.
 *
 * **Paths do not move; the disk identity does.** A promoted asset keeps the exact
 * relative path it was written to, and only `asset_disk` changes. That is the
 * same convention {@see HistoricSermonPublicationService}
 * uses in the other direction when it releases quarantine to production, so the
 * two halves of the custody chain agree about what a destination identity is.
 *
 * **Copy, verify, then delete.** New direct-pipeline bytes are streamed
 * create-only into quarantine and checked by exact size before the database is
 * touched; an existing destination is hashed against its staging source only
 * to classify an identical replay or a conflict. The live destination is
 * verified a second time after the record is bound to it. Only then is the
 * staging copy removed. The transient cost is one extra copy of the largest
 * single asset, never a second copy of the pass.
 *
 * Deletion trigger: Delete after IC8 closeout, alongside the historic-video
 * dispatcher and once every historic asset has been released or discarded.
 */
final class HistoricAssetPromotion
{
    public function __construct(
        private readonly SermonPromotionAssets $assets,
        private readonly HistoricProcessingResultAssetTransfer $transfer,
        private readonly HistoricStagingGuard $staging,
    ) {}

    /**
     * Promote every sermon and song video this run owns.
     *
     * @return array{
     *     sermons: int,
     *     song_videos: int,
     *     held_section_candidates: int,
     *     assets_promoted: int,
     *     assets_already_promoted: int,
     *     promoted_bytes: int,
     *     reclaimed_bytes: int,
     *     staging_bytes_before_reclaim: int
     * }
     */
    public function promoteRun(MediaProcessingLog $log): array
    {
        $this->staging->assertLocalProcessingIsIsolated();

        $totals = [
            'sermons' => 0,
            'song_videos' => 0,
            'held_section_candidates' => 0,
            'assets_promoted' => 0,
            'assets_already_promoted' => 0,
            'promoted_bytes' => 0,
            'reclaimed_bytes' => 0,
            /**
             * The pass's peak working bytes, sampled.
             *
             * Taken here because this is the high-water moment for a run: its
             * durable output has been written and nothing has been reclaimed
             * yet, while every other run still in flight is also holding its
             * own working copies. A continuous gauge would need a sampler
             * outside the pipeline; the maximum of these samples is what the
             * canary reports, and it is honest about being a sample.
             */
            'staging_bytes_before_reclaim' => $this->stagingBytes(),
        ];

        if ($log->historic_import_operation_id === null) {
            return $totals;
        }

        /**
         * Read once for the whole run, not per asset. A re-cut replaces the
         * sermon video and any section candidates cut from the same source, and
         * those promote after it — so spending the authority on the first asset
         * would strip it from the rest of its own run.
         */
        $replacementAuthorised = $log->permitsPromotionVideoReplacement();

        foreach ($this->sermonsForRun($log) as $sermon) {
            $result = $this->promoteSermon($sermon, $log, $replacementAuthorised);

            $totals['sermons']++;
            $totals['assets_promoted'] += $result['assets_promoted'];
            $totals['assets_already_promoted'] += $result['assets_already_promoted'];
            $totals['promoted_bytes'] += $result['promoted_bytes'];
            $totals['reclaimed_bytes'] += $result['reclaimed_bytes'];
        }

        foreach ($this->songVideosForRun($log) as $songVideo) {
            $result = $this->promoteSongVideo($songVideo, $log, $replacementAuthorised);

            $totals['song_videos']++;
            $totals['assets_promoted'] += $result['assets_promoted'];
            $totals['assets_already_promoted'] += $result['assets_already_promoted'];
            $totals['promoted_bytes'] += $result['promoted_bytes'];
            $totals['reclaimed_bytes'] += $result['reclaimed_bytes'];
        }

        foreach ($this->heldSectionCandidatesForRun($log) as $section) {
            $result = $this->promoteHeldSectionCandidate($section, $log, $replacementAuthorised);

            $totals['held_section_candidates']++;
            $totals['assets_promoted'] += $result['assets_promoted'];
            $totals['assets_already_promoted'] += $result['assets_already_promoted'];
            $totals['promoted_bytes'] += $result['promoted_bytes'];
            $totals['reclaimed_bytes'] += $result['reclaimed_bytes'];
        }

        /**
         * Spent only once every asset in the run is verified in place, so a
         * promotion that fails part-way can still be retried with the authority
         * it needs.
         */
        if ($replacementAuthorised) {
            $log->clearReExtraction();
        }

        return $totals;
    }

    /**
     * Promote only the song videos for a named run.
     *
     * The repair command uses this narrow entry point so repairing a stranded
     * song row cannot accidentally re-run the sermon side of the historic
     * custody transition.
     *
     * @return array{
     *     sermons: int,
     *     song_videos: int,
     *     held_section_candidates: int,
     *     assets_promoted: int,
     *     assets_already_promoted: int,
     *     promoted_bytes: int,
     *     reclaimed_bytes: int,
     *     staging_bytes_before_reclaim: int
     * }
     */
    public function promoteSongVideos(MediaProcessingLog $log): array
    {
        $this->staging->assertLocalProcessingIsIsolated();

        $totals = [
            'sermons' => 0,
            'song_videos' => 0,
            'held_section_candidates' => 0,
            'assets_promoted' => 0,
            'assets_already_promoted' => 0,
            'promoted_bytes' => 0,
            'reclaimed_bytes' => 0,
            'staging_bytes_before_reclaim' => $this->stagingBytes(),
        ];

        if ($log->historic_import_operation_id === null) {
            return $totals;
        }

        foreach ($this->songVideosForRun($log) as $songVideo) {
            $result = $this->promoteSongVideo($songVideo, $log);

            $totals['song_videos']++;
            $totals['assets_promoted'] += $result['assets_promoted'];
            $totals['assets_already_promoted'] += $result['assets_already_promoted'];
            $totals['promoted_bytes'] += $result['promoted_bytes'];
            $totals['reclaimed_bytes'] += $result['reclaimed_bytes'];
        }

        foreach ($this->heldSectionCandidatesForRun($log) as $section) {
            $result = $this->promoteHeldSectionCandidate($section, $log);

            $totals['held_section_candidates']++;
            $totals['assets_promoted'] += $result['assets_promoted'];
            $totals['assets_already_promoted'] += $result['assets_already_promoted'];
            $totals['promoted_bytes'] += $result['promoted_bytes'];
            $totals['reclaimed_bytes'] += $result['reclaimed_bytes'];
        }

        return $totals;
    }

    /**
     * @return array{
     *     assets_promoted: int,
     *     assets_already_promoted: int,
     *     promoted_bytes: int,
     *     reclaimed_bytes: int
     * }
     */
    private function promoteSermon(
        Sermon $sermon,
        MediaProcessingLog $log,
        bool $replacementAuthorised = false,
    ): array {
        $quarantineName = $this->transfer->targetDiskName();
        $staging = Storage::disk($this->staging->stagingDisk());
        $quarantine = Storage::disk($quarantineName);

        /**
         * Which of this record's references still have a working copy.
         *
         * Asked per asset rather than per record on purpose. A record already
         * bound to quarantine can still acquire fresh staging output — a retry
         * that resumes at extraction writes new bytes under the same paths — so
         * treating `asset_disk` as a promoted flag would silently strand them.
         */
        $pending = [];
        $alreadyPromoted = 0;

        foreach ($this->assets->referencesForSermon($sermon) as $reference) {
            $path = $reference['path'];

            if ($staging->exists($path)) {
                $pending[] = [
                    'kind' => $reference['kind'],
                    'path' => $path,
                    'size' => $staging->size($path),
                    'roles' => [$path],
                ];

                continue;
            }

            if ($quarantine->exists($path)) {
                $alreadyPromoted++;

                continue;
            }

            throw new RuntimeException(
                "Sermon {$sermon->getKey()} references {$reference['kind']} asset {$path}, which is on neither staging nor quarantine."
            );
        }

        if ($pending === []) {
            $this->bindToQuarantine($sermon, $log, $quarantineName);

            return [
                'assets_promoted' => 0,
                'assets_already_promoted' => $alreadyPromoted,
                'promoted_bytes' => 0,
                'reclaimed_bytes' => 0,
            ];
        }

        /**
         * Each asset is its own transfer role, keyed by its path. The role map
         * exists so one file can reach several logical destinations; here the
         * destination *is* the source path on the other disk, and a path is the
         * only identifier guaranteed unique across a sermon's thumbnail
         * candidates, which share their `kind`.
         *
         * A destination that already holds different bytes fails here rather
         * than being overwritten. That is a genuine conflict — the same path
         * reprocessed into different output — and belongs in front of an
         * operator, not under a silent overwrite.
         *
         * The one exception is an operator's deliberate re-cut, which is not
         * silent: `sermons:re-extract` asked for exactly this replacement and
         * {@see MediaProcessingLog::authoriseVideoReplacementOnPromotion()}
         * carries that request here. Without it a re-cut fails permanently with
         * its correct new video stranded in staging.
         */
        $destinations = [];

        foreach ($pending as $asset) {
            $destinations[$asset['path']] = $asset['path'];
        }

        $this->transfer->copyPipelineAssetsToDestinations($pending, $destinations, $replacementAuthorised);

        $this->bindToQuarantine($sermon, $log, $quarantineName);

        /**
         * Verified again after the binding commits. The first verification proved
         * the copy landed; this one proves the record now points at bytes that
         * are still there, which is the condition for removing the working copy.
         */
        $this->transfer->verifyPipelineDestinations($pending, $destinations);

        return [
            'assets_promoted' => count($pending),
            'assets_already_promoted' => $alreadyPromoted,
            'promoted_bytes' => array_sum(array_column($pending, 'size')),
            'reclaimed_bytes' => $this->removeWorkingCopies($staging, $pending),
        ];
    }

    /**
     * @return array{
     *     assets_promoted: int,
     *     assets_already_promoted: int,
     *     promoted_bytes: int,
     *     reclaimed_bytes: int
     * }
     */
    private function promoteSongVideo(
        SongVideo $songVideo,
        MediaProcessingLog $log,
        bool $replacementAuthorised = false,
    ): array {
        $path = trim($songVideo->video_file_path);

        if ($path === '') {
            throw new RuntimeException("Song video {$songVideo->getKey()} has no asset path.");
        }

        $quarantineName = $this->transfer->targetDiskName();
        $staging = Storage::disk($this->staging->stagingDisk());
        $quarantine = Storage::disk($quarantineName);
        $pending = [];
        $alreadyPromoted = 0;
        $this->assertSongVideoCanBePromoted($songVideo, $log, $quarantineName);

        if ($staging->exists($path)) {
            $pending[] = [
                'kind' => 'song_video',
                'path' => $path,
                'size' => $staging->size($path),
                'roles' => [$path],
            ];
        } elseif ($quarantine->exists($path)) {
            if (
                $songVideo->publication_state !== SermonPublicationState::Quarantined
                || $songVideo->asset_disk !== $quarantineName
                || $songVideo->historic_import_operation_id !== $log->historic_import_operation_id
            ) {
                throw new RuntimeException(
                    "Song video {$songVideo->getKey()} has quarantine bytes without a verified staging source."
                );
            }

            $alreadyPromoted++;
        } else {
            throw new RuntimeException(
                "Song video {$songVideo->getKey()} references asset {$path}, which is on neither staging nor quarantine."
            );
        }

        $destinations = [$path => $path];

        if ($pending !== []) {
            $this->transfer->copyPipelineAssetsToDestinations($pending, $destinations, $replacementAuthorised);
        }

        $this->bindSongVideoToQuarantine($songVideo, $log, $quarantineName);

        if ($pending !== []) {
            $this->transfer->verifyPipelineDestinations($pending, $destinations);
        }

        return [
            'assets_promoted' => count($pending),
            'assets_already_promoted' => $alreadyPromoted,
            'promoted_bytes' => array_sum(array_column($pending, 'size')),
            'reclaimed_bytes' => $this->removeWorkingCopies($staging, $pending),
        ];
    }

    private function assertSongVideoCanBePromoted(
        SongVideo $songVideo,
        MediaProcessingLog $log,
        string $quarantine,
    ): void {
        $staging = $this->staging->stagingDisk();

        if ($songVideo->historic_import_operation_id !== null
            && $songVideo->historic_import_operation_id !== $log->historic_import_operation_id) {
            throw new RuntimeException(
                "Song video {$songVideo->getKey()} is already owned by a different historic operation."
            );
        }

        if ($songVideo->asset_disk !== null && ! in_array($songVideo->asset_disk, [$staging, $quarantine], true)) {
            throw new RuntimeException(
                "Song video {$songVideo->getKey()} is already owned by disk {$songVideo->asset_disk}."
            );
        }

        if ($songVideo->publication_state === SermonPublicationState::Published
            && $songVideo->asset_disk !== null) {
            throw new RuntimeException(
                "Song video {$songVideo->getKey()} is published with an existing disk owner; refusing inconsistent custody."
            );
        }
    }

    /**
     * Bind the record to its promoted bytes and to the operation that produced
     * them, in one locked write.
     *
     * Sermons created by the current direct lane are already quarantined before
     * this job runs. The conditional state update remains for pre-M10 rows that
     * inherited the published default while their assets sat on staging.
     */
    private function bindToQuarantine(Sermon $sermon, MediaProcessingLog $log, string $quarantine): void
    {
        DB::transaction(function () use ($sermon, $log, $quarantine): void {
            $locked = Sermon::query()->whereKey($sermon->getKey())->lockForUpdate()->first();

            if (! $locked instanceof Sermon) {
                throw new RuntimeException("Sermon {$sermon->getKey()} disappeared while being promoted.");
            }

            $updates = [
                'asset_disk' => $quarantine,
                'historic_import_operation_id' => $log->historic_import_operation_id,
            ];

            if ($locked->publication_state !== SermonPublicationState::Quarantined) {
                $updates['publication_state'] = SermonPublicationState::Quarantined;
            }

            if (
                $locked->publication_state === SermonPublicationState::Quarantined
                && $locked->asset_disk === $updates['asset_disk']
                && $locked->historic_import_operation_id === $updates['historic_import_operation_id']
            ) {
                return;
            }

            $locked->forceFill($updates)->save();
        });

        $sermon->refresh();
    }

    /**
     * Delete only the exact paths just verified at their destination, and only
     * from the staging disk.
     *
     * There is no path-pattern sweep here on purpose: the set deleted is the set
     * proven present in quarantine moments earlier, so cleanup cannot reach a
     * source-drive file, a quarantine asset or a public one.
     *
     * @param  list<array{kind: string, path: string, size: int, roles: list<string>}>  $promoted
     */
    /**
     * Candidates a reviewer must still decide on, whose bytes are nonetheless
     * durable output of this run.
     *
     * A held candidate has no `SongVideo` row -- that row is created at
     * publication -- so enumerating song videos alone leaves these assets on the
     * working volume with nothing recording where they live. They are not
     * publishable, but they are not scratch either: the reviewer needs them, and
     * until they are promoted they can be neither attributed during a custody
     * census nor reclaimed. Promotion moves the bytes and records the disk; it
     * never touches `publication_status`, so the approval gate is untouched.
     *
     * @return Collection<int, ServiceSection>
     */
    private function heldSectionCandidatesForRun(MediaProcessingLog $log): Collection
    {
        return ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->where('publication_status', ServiceSectionPublicationStatus::PendingApproval->value)
            ->where(function (Builder $query): void {
                $query->whereNotNull('extracted_video_path')
                    ->orWhereNotNull('extracted_audio_path');
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{
     *     assets_promoted: int,
     *     assets_already_promoted: int,
     *     promoted_bytes: int,
     *     reclaimed_bytes: int
     * }
     */
    private function promoteHeldSectionCandidate(
        ServiceSection $section,
        MediaProcessingLog $log,
        bool $replacementAuthorised = false,
    ): array {
        $quarantineName = $this->transfer->targetDiskName();
        $staging = Storage::disk($this->staging->stagingDisk());
        $quarantine = Storage::disk($quarantineName);
        $stagingName = $this->staging->stagingDisk();

        if (filled($section->asset_disk)
            && ! in_array($section->asset_disk, [$stagingName, $quarantineName], true)) {
            throw new RuntimeException(
                "Service section {$section->getKey()} candidate media is already owned by disk {$section->asset_disk}."
            );
        }

        $pending = [];
        $destinations = [];
        $alreadyPromoted = 0;

        foreach ([$section->extracted_video_path, $section->extracted_audio_path] as $path) {
            if (! is_string($path) || trim($path) === '') {
                continue;
            }

            $path = trim($path);

            if ($staging->exists($path)) {
                $pending[] = [
                    'kind' => 'section_candidate',
                    'path' => $path,
                    'size' => $staging->size($path),
                    'roles' => [$path],
                ];
                $destinations[$path] = $path;

                continue;
            }

            if ($quarantine->exists($path)) {
                if ($section->asset_disk !== $quarantineName) {
                    throw new RuntimeException(
                        "Service section {$section->getKey()} has quarantine bytes without a verified staging source."
                    );
                }

                $alreadyPromoted++;

                continue;
            }

            throw new RuntimeException(
                "Service section {$section->getKey()} references candidate asset {$path}, which is on neither staging nor quarantine."
            );
        }

        if ($pending !== []) {
            $this->transfer->copyPipelineAssetsToDestinations($pending, $destinations, $replacementAuthorised);
        }

        $this->bindSectionCandidateToQuarantine($section, $quarantineName, $stagingName);

        if ($pending !== []) {
            $this->transfer->verifyPipelineDestinations($pending, $destinations);
        }

        return [
            'assets_promoted' => count($pending),
            'assets_already_promoted' => $alreadyPromoted,
            'promoted_bytes' => array_sum(array_column($pending, 'size')),
            'reclaimed_bytes' => $this->removeWorkingCopies($staging, $pending),
        ];
    }

    /**
     * Record the disk now holding the candidate media.
     *
     * Only `asset_disk` moves. `publication_status` stays `PendingApproval`
     * because promotion is a custody transition, not a review decision.
     */
    private function bindSectionCandidateToQuarantine(
        ServiceSection $section,
        string $quarantine,
        string $staging,
    ): void {
        DB::transaction(function () use ($section, $quarantine, $staging): void {
            $locked = ServiceSection::query()->whereKey($section->getKey())->lockForUpdate()->first();

            if (! $locked instanceof ServiceSection) {
                throw new RuntimeException("Service section {$section->getKey()} disappeared while being promoted.");
            }

            if (filled($locked->asset_disk) && ! in_array($locked->asset_disk, [$staging, $quarantine], true)) {
                throw new RuntimeException(
                    "Service section {$locked->getKey()} candidate media is already owned by disk {$locked->asset_disk}."
                );
            }

            if ($locked->asset_disk === $quarantine) {
                return;
            }

            $locked->forceFill(['asset_disk' => $quarantine])->save();
        });

        $section->refresh();
    }

    /**
     * Delete only the exact paths just verified at their destination, and only
     * from the staging disk.
     *
     * There is no path-pattern sweep here on purpose: the set deleted is the set
     * proven present in quarantine moments earlier, so cleanup cannot reach a
     * source-drive file, a quarantine asset or a public one.
     *
     * @param  list<array{kind: string, path: string, size: int, roles: list<string>}>  $promoted
     */
    private function removeWorkingCopies(FilesystemAdapter $staging, array $promoted): int
    {
        $reclaimed = 0;

        foreach ($promoted as $asset) {
            if (! $staging->exists($asset['path'])) {
                continue;
            }

            if (! $staging->delete($asset['path'])) {
                throw new RuntimeException("Promoted historic asset {$asset['path']} could not be removed from staging.");
            }

            $reclaimed += $asset['size'];
        }

        return $reclaimed;
    }

    /**
     * Total bytes currently held on the working staging disk.
     *
     * A metadata-only walk: sizes come from the filesystem's own records, so no
     * asset content is read.
     */
    private function stagingBytes(): int
    {
        $staging = Storage::disk($this->staging->stagingDisk());
        $bytes = 0;

        foreach ($staging->allFiles() as $path) {
            $bytes += $staging->size($path);
        }

        return $bytes;
    }

    /**
     * @return Collection<int, Sermon>
     */
    private function sermonsForRun(MediaProcessingLog $log): Collection
    {
        return Sermon::query()
            ->where(function ($query) use ($log): void {
                $query->where('livestream_processing_id', $log->processing_id);

                if ($log->sermon_id !== null) {
                    $query->orWhere('id', $log->sermon_id);
                }
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, SongVideo>
     */
    private function songVideosForRun(MediaProcessingLog $log): Collection
    {
        return SongVideo::query()
            ->whereHas('serviceSection', function (Builder $query) use ($log): void {
                $query->where('media_processing_log_id', $log->id);
            })
            ->orderBy('id')
            ->get();
    }

    private function bindSongVideoToQuarantine(
        SongVideo $songVideo,
        MediaProcessingLog $log,
        string $quarantine,
    ): void {
        $staging = $this->staging->stagingDisk();

        DB::transaction(function () use ($songVideo, $log, $quarantine, $staging): void {
            $locked = SongVideo::query()->whereKey($songVideo->getKey())->lockForUpdate()->first();

            if (! $locked instanceof SongVideo) {
                throw new RuntimeException("Song video {$songVideo->getKey()} disappeared while being promoted.");
            }

            if ($locked->historic_import_operation_id !== null
                && $locked->historic_import_operation_id !== $log->historic_import_operation_id) {
                throw new RuntimeException("Song video {$locked->getKey()} is already owned by a different historic operation.");
            }

            if ($locked->asset_disk !== null && ! in_array($locked->asset_disk, [$staging, $quarantine], true)) {
                throw new RuntimeException(
                    "Song video {$locked->getKey()} is already owned by disk {$locked->asset_disk}."
                );
            }

            $updates = [
                'publication_state' => SermonPublicationState::Quarantined,
                'asset_disk' => $quarantine,
                'historic_import_operation_id' => $log->historic_import_operation_id,
            ];

            if ($locked->publication_state === $updates['publication_state']
                && $locked->asset_disk === $updates['asset_disk']
                && $locked->historic_import_operation_id === $updates['historic_import_operation_id']) {
                return;
            }

            $locked->forceFill($updates)->save();
        });

        $songVideo->refresh();
    }
}
