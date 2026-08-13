<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Contracts\HistoricReleaseObjectStore;
use App\Data\HistoricReleaseObject;
use App\Enums\SermonPublicationState;
use App\Models\HistoricImportOperation;
use App\Models\HistoricImportReleaseAsset;
use App\Models\HistoricImportReleaseAttempt;
use App\Models\Sermon;
use App\Models\SongVideo;
use App\Services\Song\SongVideoService;
use App\Support\CanonicalJson;
use App\Support\Path;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Publish an authorised batch of quarantined historic records.
 *
 * HIR7 rebuilt this around a durable owner. The previous implementation decided
 * ownership of a final public path with `exists()` followed by `writeStream()`,
 * kept the paths it wrote in a process-local list, and deleted every path in
 * that list on any later failure. Neither half re-checked that the attempt still
 * exclusively owned the object, so two attempts could both observe the path as
 * absent, both write it, and the loser's compensation could delete the winner's
 * published asset.
 *
 * Three things changed.
 *
 * **A claim exists before any byte moves.** Destination ownership is a row with
 * a globally unique identity, taken in a short transaction that commits before a
 * source stream is opened. A second attempt for the same batch resolves the
 * attempt that already exists; a second attempt for someone else's destination
 * is refused by the database.
 *
 * **Writes go through a conditional create.** {@see HistoricReleaseObjectStore}
 * creates only if the destination is absent and reports honestly when it did
 * not. A pre-existing object with identical bytes is recorded as
 * `preexisting_verified` and is never cleanup-owned; different bytes fail
 * without overwriting.
 *
 * **Compensation never deletes by path.** HIR-D1 measured that Spaces has no
 * bucket versioning and silently ignores `IfMatch` on `DeleteObject`, so there
 * is no operation that deletes only what this attempt created. Objects this
 * attempt created and could not publish are therefore retained, recorded
 * `orphaned`, and reconciled by a human. Never trade a possible orphan for
 * deletion of a winner.
 *
 * Delete alongside the release ledger once the accepted public release and
 * rollback observation window have closed (G9/WP10).
 */
class HistoricSermonPublicationService
{
    /**
     * Which release implementation an exercise ran against.
     *
     * HIR5's object-recovery evidence names this, because evidence that a
     * destination race is survivable proves nothing if it was gathered against
     * the implementation whose compensation deleted by path. It is the declared
     * half of that check; the observed half is the ledger, which only this
     * implementation writes.
     */
    public const string ReleaseImplementation = 'hir7-claim-ledger-conditional-create-v1';

    /**
     * How long a claim owns its batch. Long enough that a real release cannot
     * outlive it, short enough that a dead attempt is recoverable the same day.
     */
    private const LeaseSeconds = 21_600;

    public function __construct(
        private readonly HistoricImportJournal $journal,
        private readonly HistoricReleaseObjectStore $objects,
        private readonly HistoricReleaseDestinationGuard $destinations,
    ) {}

    public function release(HistoricImportOperation $operation, Sermon $sermon): Sermon
    {
        return $this->releaseRecords($operation, [$sermon->id], [])['sermons'][0];
    }

    /**
     * Release an exact, enumerated batch of quarantined historic records.
     *
     * Song videos travel with the sermons deliberately. `SongVideo` has no
     * per-record disk and {@see SongVideoService::getVideoUrl()} always builds a
     * sermon-disk URL, so a song video whose state flips without its bytes
     * moving would advertise a public URL for a file that only exists on the
     * private quarantine disk.
     *
     * @param  list<int>  $sermonIds
     * @param  list<int>  $songVideoIds
     * @param  array<string, mixed>|null  $authorisation  the verified signed release authority
     * @return array{sermons: list<Sermon>, song_videos: list<SongVideo>}
     */
    public function releaseRecords(
        HistoricImportOperation $operation,
        array $sermonIds,
        array $songVideoIds,
        ?array $authorisation = null,
    ): array {
        $targetDiskName = (string) config('media-processing.storage.sermon_disk');
        $quarantineDiskName = (string) config('media-processing.storage.historic_quarantine_disk');
        $sermons = $this->operationSermons($operation, $sermonIds);
        $songVideos = $this->operationSongVideos($operation, $songVideoIds);

        /**
         * Plan §4.2.1, asked once and up front. The object store refuses the
         * same write as its last line, but asking here means a refused release
         * leaves no claims behind to reconcile.
         */
        $this->destinations->assertWritable($targetDiskName);

        /**
         * Claim, then work. The transaction closes before the first stream is
         * opened, so no database transaction is ever held across object I/O.
         *
         * The claim comes before the quarantine check because a retry of a
         * completed batch has to be an exact no-op, and by then its records are
         * published rather than quarantined. The attempt is what says which of
         * those two situations this is.
         */
        $attempt = $this->claim(
            $operation,
            $authorisation,
            $sermons,
            $songVideos,
            $targetDiskName,
        );

        if ($attempt->state === HistoricImportReleaseAttempt::StateCompleted) {
            return $this->alreadyReleased($attempt, $sermons, $songVideos);
        }

        $this->assertQuarantined($sermons, $songVideos);
        $sermonPaths = [];

        foreach ($sermons as $sermon) {
            $this->assertDistinctDisks((string) $sermon->asset_disk, $targetDiskName);
            $sermonPaths[$sermon->id] = $this->assetPaths($sermon);
        }

        if ($songVideos !== []) {
            $this->assertDistinctDisks($quarantineDiskName, $targetDiskName);
        }

        $this->claimDestinationsOnce(
            $attempt,
            $sermons,
            $songVideos,
            $sermonPaths,
            $quarantineDiskName,
            $targetDiskName,
        );

        try {
            $this->publishObjects($attempt);

            $released = DB::transaction(fn (): array => $this->commit(
                $operation,
                $attempt,
                $sermons,
                $songVideos,
                $sermonPaths,
                $quarantineDiskName,
                $targetDiskName,
            ));
        } catch (Throwable $exception) {
            $this->compensate($attempt, $exception);

            throw $exception;
        }

        return $released;
    }

    /**
     * Take, or resolve, the one owner of this exact signed batch.
     *
     * A completed attempt is returned as-is so a retry is an exact no-op. A live
     * claim is refused rather than joined. An expired claim is taken over
     * through this same path, which is the explicit reconciliation the plan
     * asks for: the assets it already owns keep their recorded state, so an
     * object the dead attempt created is not written twice.
     *
     * @param  array<string, mixed>|null  $authorisation
     * @param  list<Sermon>  $sermons
     * @param  list<SongVideo>  $songVideos
     */
    private function claim(
        HistoricImportOperation $operation,
        ?array $authorisation,
        array $sermons,
        array $songVideos,
        string $targetDiskName,
    ): HistoricImportReleaseAttempt {
        $authorisationHash = CanonicalJson::hash(
            $authorisation ?? ['unauthorised_direct_release' => true],
        );
        $membershipHash = CanonicalJson::hash([
            'sermon_ids' => array_map(static fn (Sermon $sermon): int => $sermon->id, $sermons),
            'song_video_ids' => array_map(static fn (SongVideo $video): int => $video->id, $songVideos),
            'target_disk' => $targetDiskName,
        ]);

        return DB::transaction(function () use (
            $operation,
            $authorisation,
            $authorisationHash,
            $membershipHash,
        ): HistoricImportReleaseAttempt {
            $existing = HistoricImportReleaseAttempt::query()
                ->where('historic_import_operation_id', $operation->id)
                ->where('authorisation_hash', $authorisationHash)
                ->where('membership_hash', $membershipHash)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof HistoricImportReleaseAttempt) {
                return $this->resumeAttempt($existing);
            }

            return HistoricImportReleaseAttempt::query()->create([
                'attempt_id' => (string) Str::uuid(),
                'historic_import_operation_id' => $operation->id,
                'authorisation_id' => is_array($authorisation) ? ($authorisation['authorisation_id'] ?? null) : null,
                'batch_key' => is_array($authorisation) ? ($authorisation['batch_key'] ?? null) : null,
                'authorisation_hash' => $authorisationHash,
                'membership_hash' => $membershipHash,
                'state' => HistoricImportReleaseAttempt::StateClaimed,
                'lease_token' => (string) Str::uuid(),
                'lease_expires_at' => now()->addSeconds(self::LeaseSeconds),
                'started_at' => now(),
            ]);
        });
    }

    /**
     * Claim every destination this batch needs, once.
     *
     * A resumed attempt already owns its claims, and re-taking them would fail
     * against its own global uniqueness. This runs in its own short transaction
     * so a partial claim never survives: either the attempt owns the whole batch
     * or it owns none of it.
     *
     * @param  list<Sermon>  $sermons
     * @param  list<SongVideo>  $songVideos
     * @param  array<int, list<string>>  $sermonPaths
     */
    private function claimDestinationsOnce(
        HistoricImportReleaseAttempt $attempt,
        array $sermons,
        array $songVideos,
        array $sermonPaths,
        string $quarantineDiskName,
        string $targetDiskName,
    ): void {
        if ($attempt->assets()->exists()) {
            return;
        }

        DB::transaction(fn () => $this->claimDestinations(
            $attempt,
            $sermons,
            $songVideos,
            $sermonPaths,
            $quarantineDiskName,
            $targetDiskName,
        ));
    }

    private function resumeAttempt(HistoricImportReleaseAttempt $attempt): HistoricImportReleaseAttempt
    {
        if ($attempt->state === HistoricImportReleaseAttempt::StateCompleted) {
            return $attempt;
        }

        if ($attempt->state === HistoricImportReleaseAttempt::StateOrphaned) {
            throw new RuntimeException(
                "Release attempt {$attempt->attempt_id} left objects it could not take back. Reconcile the orphaned "
                .'release assets before attempting this batch again.'
            );
        }

        if ($attempt->state === HistoricImportReleaseAttempt::StateClaimed
            && $attempt->lease_expires_at->isFuture()) {
            throw new RuntimeException(
                "Release attempt {$attempt->attempt_id} holds a live claim on this batch until "
                .$attempt->lease_expires_at->toIso8601String().'. A second releaser never takes an owned batch.'
            );
        }

        $attempt->forceFill([
            'state' => HistoricImportReleaseAttempt::StateClaimed,
            'lease_token' => (string) Str::uuid(),
            'lease_expires_at' => now()->addSeconds(self::LeaseSeconds),
            'failure_summary' => null,
            'failed_at' => null,
        ])->save();

        return $attempt;
    }

    /**
     * @param  list<Sermon>  $sermons
     * @param  list<SongVideo>  $songVideos
     * @param  array<int, list<string>>  $sermonPaths
     */
    private function claimDestinations(
        HistoricImportReleaseAttempt $attempt,
        array $sermons,
        array $songVideos,
        array $sermonPaths,
        string $quarantineDiskName,
        string $targetDiskName,
    ): void {
        $claims = [];

        foreach ($sermons as $sermon) {
            foreach ($sermonPaths[$sermon->id] as $path) {
                $claims[] = [
                    'record_type' => HistoricImportReleaseAsset::TypeSermon,
                    'record_id' => $sermon->id,
                    'source_disk' => (string) $sermon->asset_disk,
                    'path' => $path,
                ];
            }
        }

        foreach ($songVideos as $songVideo) {
            $claims[] = [
                'record_type' => HistoricImportReleaseAsset::TypeSongVideo,
                'record_id' => $songVideo->id,
                'source_disk' => $quarantineDiskName,
                'path' => (string) $songVideo->video_file_path,
            ];
        }

        /**
         * A stable order across sermon and song-video membership. Two attempts
         * whose batches overlap take their claims in the same sequence, so they
         * cannot deadlock holding half of each other's rows.
         */
        usort($claims, static fn (array $a, array $b): int => [$a['record_type'], $a['record_id'], $a['path']]
            <=> [$b['record_type'], $b['record_id'], $b['path']]);

        foreach ($claims as $claim) {
            $this->claimDestination(
                $attempt,
                $claim['record_type'],
                $claim['record_id'],
                $claim['source_disk'],
                $claim['path'],
                $targetDiskName,
            );
        }
    }

    private function claimDestination(
        HistoricImportReleaseAttempt $attempt,
        string $recordType,
        int $recordId,
        string $sourceDisk,
        string $path,
        string $targetDisk,
    ): void {
        if (Path::isUnsafe($path)) {
            throw new RuntimeException("Quarantined sermon asset is missing or unsafe: {$path}.");
        }

        $identity = HistoricImportReleaseAsset::identityFor($targetDisk, $path);

        try {
            HistoricImportReleaseAsset::query()->create([
                'historic_import_release_attempt_id' => $attempt->id,
                'record_type' => $recordType,
                'record_id' => $recordId,
                'source_disk' => $sourceDisk,
                'source_path' => $path,
                'destination_disk' => $targetDisk,
                'destination_path' => $path,
                'destination_identity' => $identity,
                'state' => HistoricImportReleaseAsset::StateClaimed,
            ]);
        } catch (QueryException $exception) {
            $owner = HistoricImportReleaseAsset::query()
                ->where('destination_identity', $identity)
                ->first();

            if (! $owner instanceof HistoricImportReleaseAsset) {
                throw $exception;
            }

            throw new RuntimeException(
                "Public destination {$targetDisk}:{$path} is already owned by release attempt "
                .($owner->attempt->attempt_id ?? 'unknown').'. One destination has one owner.',
                previous: $exception,
            );
        }
    }

    /**
     * Stream every claimed asset through the conditional create and verify it.
     *
     * Ordering matters and is the same as it was: hash the source, then look at
     * the destination, then open the source stream. A test that pins the race
     * to a particular read is pinning this sequence.
     */
    private function publishObjects(HistoricImportReleaseAttempt $attempt): void
    {
        $assets = $attempt->assets()->orderBy('id')->get();

        foreach ($assets as $asset) {
            if (in_array($asset->state, [
                HistoricImportReleaseAsset::StateCreatedVerified,
                HistoricImportReleaseAsset::StatePreexistingVerified,
                HistoricImportReleaseAsset::StatePublished,
            ], true)) {
                continue;
            }

            $this->publishObject($asset);
        }
    }

    private function publishObject(HistoricImportReleaseAsset $asset): void
    {
        $source = Storage::disk($asset->source_disk);

        if (! $source->exists($asset->source_path)) {
            throw new RuntimeException("Quarantined sermon asset is missing or unsafe: {$asset->source_path}.");
        }

        $sourceHash = $this->hash($source, $asset->source_path);
        $sourceSize = (int) $source->size($asset->source_path);

        $existing = $this->objects->inspect($asset->destination_disk, $asset->destination_path);

        if ($existing instanceof HistoricReleaseObject) {
            $this->recordPreexisting($asset, $existing, $sourceSize, $sourceHash);

            return;
        }

        $stream = $source->readStream($asset->source_path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Quarantined sermon asset cannot be read: {$asset->source_path}.");
        }

        try {
            $object = $this->objects->createIfAbsent($asset->destination_disk, $asset->destination_path, $stream);
        } finally {
            fclose($stream);
        }

        if (! $object->createdByThisAttempt) {
            /**
             * A foreign writer landed between the inspection and the create. The
             * conditional create refused rather than overwriting, so the bytes
             * there are theirs — acceptable only if they are the same bytes, and
             * never this attempt's to clean up either way.
             */
            $this->recordPreexisting($asset, $object, $sourceSize, $sourceHash);

            return;
        }

        /**
         * State before verification, not after. If the process dies here the
         * ledger already says this attempt created the object, which is what
         * makes it a retained orphan rather than an unattributed one.
         */
        $asset->forceFill([
            'state' => HistoricImportReleaseAsset::StateCreatedVerified,
            'create_result' => 'created',
            'size' => $object->size,
            'sha256' => $object->sha256,
            'provider_receipt' => $object->receipt,
            'provider_version_id' => $object->versionId,
        ])->save();

        $verified = $this->objects->verify(
            $asset->destination_disk,
            $asset->destination_path,
            $sourceSize,
            $sourceHash,
        );

        $asset->forceFill([
            'size' => $verified->size,
            'sha256' => $verified->sha256,
            'provider_receipt' => $verified->receipt,
            'verified_at' => now(),
        ])->save();
    }

    private function recordPreexisting(
        HistoricImportReleaseAsset $asset,
        HistoricReleaseObject $object,
        int $sourceSize,
        string $sourceHash,
    ): void {
        if (! $object->matches($sourceSize, $sourceHash)) {
            $asset->forceFill([
                'state' => HistoricImportReleaseAsset::StateAbandoned,
                'create_result' => 'preexisting',
                'size' => $object->size,
                'sha256' => $object->sha256,
            ])->save();

            throw new RuntimeException(
                "Public sermon asset path contains different bytes: {$asset->destination_path}.",
            );
        }

        $asset->forceFill([
            'state' => HistoricImportReleaseAsset::StatePreexistingVerified,
            'create_result' => 'preexisting',
            'size' => $object->size,
            'sha256' => $object->sha256,
            'provider_receipt' => $object->receipt,
            'provider_version_id' => $object->versionId,
            'verified_at' => now(),
        ])->save();
    }

    /**
     * Durable state first, then compensation.
     *
     * Compensation here can only ever be "retain and record". Exact-version
     * deletion does not exist on either store, and deleting by path would delete
     * whatever is at the path — which, in the race this package exists to close,
     * is the winner's published bytes.
     */
    private function compensate(HistoricImportReleaseAttempt $attempt, Throwable $exception): void
    {
        $created = $attempt->assets()
            ->where('create_result', 'created')
            ->whereNull('published_at')
            ->get();

        foreach ($created as $asset) {
            $asset->forceFill([
                'state' => HistoricImportReleaseAsset::StateOrphaned,
                'compensated_at' => now(),
            ])->save();
        }

        $attempt->assets()
            ->where('state', HistoricImportReleaseAsset::StateClaimed)
            ->update([
                'state' => HistoricImportReleaseAsset::StateAbandoned,
                'compensated_at' => now(),
                'updated_at' => now(),
            ]);

        $attempt->forceFill([
            'state' => $created->isEmpty()
                ? HistoricImportReleaseAttempt::StateFailed
                : HistoricImportReleaseAttempt::StateOrphaned,
            'failure_summary' => Str::limit($exception->getMessage(), 480),
            'failed_at' => now(),
            'lease_expires_at' => now(),
        ])->save();
    }

    /**
     * A completed attempt asked to run again: an exact no-op.
     *
     * Nothing is written and no object is touched. What is checked is that the
     * records the completed attempt names really are published — a completed
     * attempt over quarantined records would mean the ledger and the database
     * disagree, and continuing on that basis would republish bytes under a
     * completed owner.
     *
     * @param  list<Sermon>  $sermons
     * @param  list<SongVideo>  $songVideos
     * @return array{sermons: list<Sermon>, song_videos: list<SongVideo>}
     */
    private function alreadyReleased(
        HistoricImportReleaseAttempt $attempt,
        array $sermons,
        array $songVideos,
    ): array {
        foreach ($sermons as $sermon) {
            if ($sermon->publication_state !== SermonPublicationState::Published) {
                throw new RuntimeException(
                    "Release attempt {$attempt->attempt_id} completed, but sermon {$sermon->id} is not published. "
                    .'Reconcile the release ledger against the records before releasing anything else.'
                );
            }
        }

        foreach ($songVideos as $songVideo) {
            if ($songVideo->publication_state !== SermonPublicationState::Published) {
                throw new RuntimeException(
                    "Release attempt {$attempt->attempt_id} completed, but song video {$songVideo->id} is not "
                    .'published. Reconcile the release ledger against the records before releasing anything else.'
                );
            }
        }

        return ['sermons' => $sermons, 'song_videos' => $songVideos];
    }

    /**
     * @param  list<Sermon>  $sermons
     * @param  list<SongVideo>  $songVideos
     * @param  array<int, list<string>>  $sermonPaths
     * @return array{sermons: list<Sermon>, song_videos: list<SongVideo>}
     */
    private function commit(
        HistoricImportOperation $operation,
        HistoricImportReleaseAttempt $attempt,
        array $sermons,
        array $songVideos,
        array $sermonPaths,
        string $quarantineDiskName,
        string $targetDiskName,
    ): array {
        $releasedSermons = [];
        $releasedSongVideos = [];

        foreach ($sermons as $sermon) {
            $sourceDiskName = (string) $sermon->asset_disk;
            $locked = Sermon::query()->whereKey($sermon->id)->lockForUpdate()->firstOrFail();

            if ($locked->historic_import_operation_id !== $operation->id
                || $locked->publication_state !== SermonPublicationState::Quarantined
                || $locked->asset_disk !== $sourceDiskName) {
                throw new RuntimeException('Historic sermon release binding changed before commit.');
            }

            $locked->forceFill([
                'publication_state' => SermonPublicationState::Published,
                'asset_disk' => $targetDiskName,
            ])->save();

            $this->journal->append($operation, 'sermon_released', [
                'sermon_id' => $locked->id,
                'attempt_id' => $attempt->attempt_id,
                'source_disk' => $sourceDiskName,
                'target_disk' => $targetDiskName,
                'asset_paths' => $sermonPaths[$sermon->id],
            ]);

            $releasedSermons[] = $locked->fresh() ?? $locked;
        }

        foreach ($songVideos as $songVideo) {
            $locked = SongVideo::query()->whereKey($songVideo->id)->lockForUpdate()->firstOrFail();

            if ($locked->historic_import_operation_id !== $operation->id
                || $locked->publication_state !== SermonPublicationState::Quarantined) {
                throw new RuntimeException('Historic song video release binding changed before commit.');
            }

            $locked->forceFill(['publication_state' => SermonPublicationState::Published])->save();

            $this->journal->append($operation, 'song_video_released', [
                'song_video_id' => $locked->id,
                'attempt_id' => $attempt->attempt_id,
                'source_disk' => $quarantineDiskName,
                'target_disk' => $targetDiskName,
                'asset_paths' => [$locked->video_file_path],
            ]);

            $releasedSongVideos[] = $locked->fresh() ?? $locked;
        }

        $attempt->assets()->update([
            'state' => HistoricImportReleaseAsset::StatePublished,
            'published_at' => now(),
            'updated_at' => now(),
        ]);
        $attempt->forceFill([
            'state' => HistoricImportReleaseAttempt::StateCompleted,
            'completed_at' => now(),
        ])->save();

        return ['sermons' => $releasedSermons, 'song_videos' => $releasedSongVideos];
    }

    /**
     * Exact membership, resolved before a single byte is copied: every id must
     * exist and belong to this operation.
     *
     * The quarantine check is separate, and runs after the attempt is resolved,
     * because a retry of a completed batch names records that are published by
     * now. Folding the two together would make an exact no-op impossible to
     * express.
     *
     * @param  list<int>  $sermonIds
     * @return list<Sermon>
     */
    private function operationSermons(HistoricImportOperation $operation, array $sermonIds): array
    {
        if ($sermonIds === []) {
            return [];
        }

        $sermons = Sermon::query()
            ->whereKey($sermonIds)
            ->where('historic_import_operation_id', $operation->id)
            ->orderBy('id')
            ->get();

        if ($sermons->count() !== count(array_unique($sermonIds))) {
            throw new RuntimeException(
                'Release membership is not exact: every named sermon must be a quarantined record of this operation.',
            );
        }

        return array_values($sermons->all());
    }

    /**
     * @param  list<int>  $songVideoIds
     * @return list<SongVideo>
     */
    private function operationSongVideos(HistoricImportOperation $operation, array $songVideoIds): array
    {
        if ($songVideoIds === []) {
            return [];
        }

        $songVideos = SongVideo::query()
            ->whereKey($songVideoIds)
            ->where('historic_import_operation_id', $operation->id)
            ->orderBy('id')
            ->get();

        if ($songVideos->count() !== count(array_unique($songVideoIds))) {
            throw new RuntimeException(
                'Release membership is not exact: every named song video must be a quarantined record of this operation.',
            );
        }

        return array_values($songVideos->all());
    }

    /**
     * @param  list<Sermon>  $sermons
     * @param  list<SongVideo>  $songVideos
     */
    private function assertQuarantined(array $sermons, array $songVideos): void
    {
        foreach ($sermons as $sermon) {
            if ($sermon->publication_state !== SermonPublicationState::Quarantined) {
                throw new RuntimeException(
                    'Release membership is not exact: every named sermon must be a quarantined record of this operation.',
                );
            }
        }

        foreach ($songVideos as $songVideo) {
            if ($songVideo->publication_state !== SermonPublicationState::Quarantined) {
                throw new RuntimeException(
                    'Release membership is not exact: every named song video must be a quarantined record of this operation.',
                );
            }
        }
    }

    private function assertDistinctDisks(string $sourceDiskName, string $targetDiskName): void
    {
        if ($sourceDiskName === '' || $targetDiskName === '' || $sourceDiskName === $targetDiskName) {
            throw new RuntimeException('Historic release requires distinct private and public media disks.');
        }
    }

    /**
     * @return list<string>
     */
    private function assetPaths(Sermon $sermon): array
    {
        $paths = [];

        foreach (['audio_file_path', 'video_file_path', 'transcript_file_path', 'thumbnail_file_path'] as $field) {
            $value = $sermon->getAttribute($field);

            if (is_string($value) && $value !== '') {
                $paths[] = $value;
            }
        }

        $this->collectMetadataPaths($sermon->thumbnail_metadata?->toArray() ?? [], $paths);

        return array_values(array_unique($paths));
    }

    /**
     * @param  array<string|int, mixed>  $values
     * @param  list<string>  $paths
     */
    private function collectMetadataPaths(array $values, array &$paths): void
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $this->collectMetadataPaths($value, $paths);

                continue;
            }

            if (is_string($key) && str_ends_with($key, '_path') && is_string($value) && $value !== '') {
                $paths[] = $value;
            }
        }
    }

    private function hash(FilesystemAdapter $disk, string $path): string
    {
        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Sermon asset cannot be hashed: {$path}.");
        }

        $hash = hash_init('sha256');

        try {
            hash_update_stream($hash, $stream);

            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }
}
