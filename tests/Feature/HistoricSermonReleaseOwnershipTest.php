<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\HistoricReleaseObjectStore;
use App\Data\HistoricReleaseObject;
use App\Enums\SermonPublicationState;
use App\Enums\SermonService;
use App\Models\HistoricImportOperation;
use App\Models\HistoricImportReleaseAsset;
use App\Models\HistoricImportReleaseAttempt;
use App\Models\Sermon;
use App\Services\Import\FilesystemHistoricReleaseObjectStore;
use App\Services\Import\HistoricImportResourceIdentity;
use App\Services\Import\HistoricSermonPublicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\PausingHistoricReleaseObjectStore;
use Tests\TestCase;

/**
 * HIR7's concurrency and fault matrix.
 *
 * Races are made deterministic rather than simulated with threads: the object
 * store is a real one wrapped in a pause, so a competing writer runs at the
 * exact instant the attempt under test is between two steps. A test that paused
 * anywhere else would pass without ever reaching the window the defect lives in.
 *
 * Every case asserts the same four things where they apply: the publication
 * state of the records, the ledger's ownership, and — most importantly — that
 * every advertised asset still exists with its original bytes.
 */
class HistoricSermonReleaseOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private const AudioPath = 'sermons/historic/audio.mp3';

    private const AudioBytes = 'released-sermon-audio-bytes';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'church.services.public_from' => '2000-01-01',
            'media-processing.storage.historic_quarantine_disk' => 'historic_quarantine',
            'media-processing.storage.sermon_disk' => 'public',
        ]);
        Storage::fake('historic_quarantine');
        Storage::fake('public');
    }

    #[Test]
    public function a_completed_release_records_its_ledger_ownership(): void
    {
        [$operation, $sermon] = $this->quarantinedBatch();

        app(HistoricSermonPublicationService::class)->releaseRecords($operation, [$sermon->id], []);

        $attempt = HistoricImportReleaseAttempt::query()->sole();
        $this->assertSame(HistoricImportReleaseAttempt::StateCompleted, $attempt->state);
        $this->assertNotNull($attempt->completed_at);

        $asset = HistoricImportReleaseAsset::query()->sole();
        $this->assertSame(HistoricImportReleaseAsset::StatePublished, $asset->state);
        $this->assertSame('created', $asset->create_result);
        $this->assertSame('public', $asset->destination_disk);
        $this->assertSame(self::AudioPath, $asset->destination_path);
        $this->assertSame(hash('sha256', self::AudioBytes), $asset->sha256);
        $this->assertNotNull($asset->verified_at);
        $this->assertNotNull($asset->published_at);
    }

    /** Retrying a completed batch changes nothing and writes nothing. */
    #[Test]
    public function a_second_release_of_a_completed_batch_is_an_exact_no_op(): void
    {
        [$operation, $sermon] = $this->quarantinedBatch();
        $service = app(HistoricSermonPublicationService::class);

        $service->releaseRecords($operation, [$sermon->id], []);
        $published = Storage::disk('public')->lastModified(self::AudioPath);

        $again = $service->releaseRecords($operation, [$sermon->id], []);

        $this->assertSame(SermonPublicationState::Published, $again['sermons'][0]->publication_state);
        $this->assertSame(1, HistoricImportReleaseAttempt::query()->count());
        $this->assertSame(1, HistoricImportReleaseAsset::query()->count());
        $this->assertSame($published, Storage::disk('public')->lastModified(self::AudioPath));
        $this->assertSame(self::AudioBytes, Storage::disk('public')->get(self::AudioPath));
    }

    /**
     * The claim is what makes ownership durable: a second attempt for a
     * destination another attempt already claimed is refused by the database,
     * before it has looked at storage at all.
     */
    #[Test]
    public function a_second_operation_cannot_claim_a_destination_another_attempt_owns(): void
    {
        [$firstOperation, $firstSermon] = $this->quarantinedBatch();
        [$secondOperation, $secondSermon] = $this->quarantinedBatch('historic-'.str_repeat('9', 32));
        $service = app(HistoricSermonPublicationService::class);

        $service->releaseRecords($firstOperation, [$firstSermon->id], []);

        try {
            $service->releaseRecords($secondOperation, [$secondSermon->id], []);
            $this->fail('Two operations both claimed one public destination.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('is already owned by release attempt', $exception->getMessage());
        }

        $this->assertSame(self::AudioBytes, Storage::disk('public')->get(self::AudioPath));
        $this->assertDatabaseHas('sermons', [
            'id' => $secondSermon->id,
            'publication_state' => SermonPublicationState::Quarantined->value,
        ]);
    }

    /** A live claim on the same batch belongs to somebody; it is not joined. */
    #[Test]
    public function a_live_claim_on_the_same_batch_is_refused(): void
    {
        [$operation, $sermon] = $this->quarantinedBatch();
        $this->claimedAttempt($operation, leaseExpiresAt: now()->addHour());

        try {
            app(HistoricSermonPublicationService::class)->releaseRecords($operation, [$sermon->id], []);
            $this->fail('A live claim was taken over.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('holds a live claim on this batch', $exception->getMessage());
        }

        Storage::disk('public')->assertMissing(self::AudioPath);
    }

    /** An expired claim is recoverable, through the same explicit path. */
    #[Test]
    public function an_expired_claim_is_taken_over_and_completes(): void
    {
        [$operation, $sermon] = $this->quarantinedBatch();
        $abandoned = $this->claimedAttempt($operation, leaseExpiresAt: now()->subMinute());

        app(HistoricSermonPublicationService::class)->releaseRecords($operation, [$sermon->id], []);

        $this->assertSame(
            HistoricImportReleaseAttempt::StateCompleted,
            $abandoned->fresh()?->state,
            'Reconciliation resumes the abandoned attempt rather than creating a second owner.',
        );
        $this->assertSame(1, HistoricImportReleaseAttempt::query()->count());
        Storage::disk('public')->assertExists(self::AudioPath);
    }

    /**
     * A foreign writer lands between the inspection and the create. The
     * conditional create refuses rather than overwriting, so the bytes there are
     * theirs — and identical, so the release may proceed and must never treat
     * the object as its own to clean up.
     */
    #[Test]
    public function a_foreign_identical_object_written_mid_release_is_never_cleanup_owned(): void
    {
        [$operation, $sermon] = $this->quarantinedBatch();
        $this->pauseObjectStoreBeforeCreate(function (): void {
            Storage::disk('public')->put(self::AudioPath, self::AudioBytes);
        });

        app(HistoricSermonPublicationService::class)->releaseRecords($operation, [$sermon->id], []);

        $asset = HistoricImportReleaseAsset::query()->sole();
        $this->assertSame('preexisting', $asset->create_result);
        $this->assertSame(HistoricImportReleaseAsset::StatePublished, $asset->state);
        $this->assertSame(self::AudioBytes, Storage::disk('public')->get(self::AudioPath));
    }

    /** Different bytes at the destination fail, and are not overwritten. */
    #[Test]
    public function a_foreign_object_with_different_bytes_fails_without_overwriting(): void
    {
        [$operation, $sermon] = $this->quarantinedBatch();
        Storage::disk('public')->put(self::AudioPath, 'somebody-elses-bytes');

        try {
            app(HistoricSermonPublicationService::class)->releaseRecords($operation, [$sermon->id], []);
            $this->fail('A destination holding different bytes was released over.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('contains different bytes', $exception->getMessage());
        }

        $this->assertSame('somebody-elses-bytes', Storage::disk('public')->get(self::AudioPath));
        $this->assertSame(
            HistoricImportReleaseAttempt::StateFailed,
            HistoricImportReleaseAttempt::query()->sole()->state,
            'Nothing was created, so there is nothing to orphan.',
        );
    }

    /**
     * A failure after the object was created cannot take the object back,
     * because exact-version deletion does not exist on either store. The object
     * is retained, the attempt records `orphaned`, and release completion is
     * blocked until a human reconciles it.
     */
    #[Test]
    public function a_failure_after_creation_retains_the_object_as_an_orphan(): void
    {
        [$operation, $sermon] = $this->quarantinedBatch();
        $this->pauseObjectStoreAfterCreate(function () use ($sermon): void {
            /** The binding moves under the attempt, so its commit refuses. */
            Sermon::query()->whereKey($sermon->id)->update([
                'publication_state' => SermonPublicationState::Published->value,
                'asset_disk' => 'public',
            ]);
        });

        try {
            app(HistoricSermonPublicationService::class)->releaseRecords($operation, [$sermon->id], []);
            $this->fail('The commit-time binding check did not refuse.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('binding changed before commit', $exception->getMessage());
        }

        $attempt = HistoricImportReleaseAttempt::query()->sole();
        $this->assertSame(HistoricImportReleaseAttempt::StateOrphaned, $attempt->state);
        $this->assertNotNull($attempt->failure_summary);

        $asset = HistoricImportReleaseAsset::query()->sole();
        $this->assertSame(HistoricImportReleaseAsset::StateOrphaned, $asset->state);
        $this->assertNotNull($asset->compensated_at);

        /** Retained, not deleted. The path may be advertised by whoever owns it. */
        Storage::disk('public')->assertExists(self::AudioPath);
        $this->assertSame(self::AudioBytes, Storage::disk('public')->get(self::AudioPath));
    }

    /** And an orphaned attempt is not silently retried over its own leftovers. */
    #[Test]
    public function an_orphaned_attempt_blocks_the_batch_until_it_is_reconciled(): void
    {
        [$operation, $sermon] = $this->quarantinedBatch();
        $this->claimedAttempt(
            $operation,
            leaseExpiresAt: now()->subMinute(),
            state: HistoricImportReleaseAttempt::StateOrphaned,
        );

        try {
            app(HistoricSermonPublicationService::class)->releaseRecords($operation, [$sermon->id], []);
            $this->fail('An orphaned attempt was resumed without reconciliation.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('left objects it could not take back', $exception->getMessage());
        }

        Storage::disk('public')->assertMissing(self::AudioPath);
    }

    /**
     * Plan §4.2.1 reaching the release itself. `.env` resolves local dev's
     * public sermon disk to the production bucket, and HIR-D2 demoted the
     * storage anchor so the production guard will not stop that. This does, and
     * it stops it before any destination is claimed.
     */
    #[Test]
    public function a_non_production_release_to_the_production_destination_is_refused_before_any_claim(): void
    {
        [$operation, $sermon] = $this->quarantinedBatch();
        config([
            'church.historic_corpus.production_storage_anchor' => app(HistoricImportResourceIdentity::class)
                ->anchorFor('public'),
            'church.historic_corpus.allow_non_production_release_destination' => false,
        ]);

        try {
            app(HistoricSermonPublicationService::class)->releaseRecords($operation, [$sermon->id], []);
            $this->fail('A non-production process published to the recorded production destination.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('recorded production storage anchor', $exception->getMessage());
        }

        Storage::disk('public')->assertMissing(self::AudioPath);
        $this->assertSame(0, HistoricImportReleaseAttempt::query()->count());
        $this->assertSame(0, HistoricImportReleaseAsset::query()->count());
        $this->assertDatabaseHas('sermons', [
            'id' => $sermon->id,
            'publication_state' => SermonPublicationState::Quarantined->value,
        ]);
    }

    /**
     * Neither store can delete an exact version, and both must say so rather
     * than emulating one. A local fake that could would certify a capability
     * production lacks.
     */
    #[Test]
    public function no_store_offers_exact_version_deletion(): void
    {
        $store = app(FilesystemHistoricReleaseObjectStore::class);

        $this->assertFalse($store->supportsExactVersionDelete('public'));
        $this->assertFalse($store->supportsExactVersionDelete('do_spaces'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Exact-version deletion is unavailable');

        $store->deleteExactVersion(new HistoricReleaseObject(
            disk: 'public',
            path: self::AudioPath,
            size: strlen(self::AudioBytes),
            sha256: hash('sha256', self::AudioBytes),
        ));
    }

    /** The conditional create is genuine: a present destination is not truncated. */
    #[Test]
    public function the_local_store_creates_only_when_the_destination_is_absent(): void
    {
        $store = app(FilesystemHistoricReleaseObjectStore::class);
        Storage::disk('historic_quarantine')->put(self::AudioPath, self::AudioBytes);

        $first = $this->createThrough($store, self::AudioPath);
        $this->assertTrue($first->createdByThisAttempt);

        Storage::disk('historic_quarantine')->put('other.mp3', 'different-bytes-entirely');
        $second = $this->createThrough($store, self::AudioPath, 'other.mp3');

        $this->assertFalse($second->createdByThisAttempt, 'A present destination must refuse the create.');
        $this->assertSame(
            self::AudioBytes,
            Storage::disk('public')->get(self::AudioPath),
            'A refused conditional create must not have truncated the destination.',
        );
    }

    private function createThrough(
        FilesystemHistoricReleaseObjectStore $store,
        string $destination,
        ?string $sourcePath = null,
    ): HistoricReleaseObject {
        $stream = Storage::disk('historic_quarantine')->readStream($sourcePath ?? $destination);

        try {
            return $store->createIfAbsent('public', $destination, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function pauseObjectStoreBeforeCreate(callable $competitor): void
    {
        $this->app->instance(
            HistoricReleaseObjectStore::class,
            new PausingHistoricReleaseObjectStore(
                app(FilesystemHistoricReleaseObjectStore::class),
                beforeCreate: $competitor,
            ),
        );
    }

    private function pauseObjectStoreAfterCreate(callable $competitor): void
    {
        $this->app->instance(
            HistoricReleaseObjectStore::class,
            new PausingHistoricReleaseObjectStore(
                app(FilesystemHistoricReleaseObjectStore::class),
                afterCreate: $competitor,
            ),
        );
    }

    private function claimedAttempt(
        HistoricImportOperation $operation,
        \DateTimeInterface $leaseExpiresAt,
        string $state = HistoricImportReleaseAttempt::StateClaimed,
    ): HistoricImportReleaseAttempt {
        $service = app(HistoricSermonPublicationService::class);
        $reflection = new \ReflectionMethod($service, 'claim');

        /**
         * Built through the service's own claim so the batch identity matches
         * what a real second attempt would compute; a hand-written hash would
         * make the test pass by describing a different batch.
         */
        $attempt = $reflection->invoke(
            $service,
            $operation,
            null,
            array_values(Sermon::query()
                ->where('historic_import_operation_id', $operation->id)
                ->orderBy('id')
                ->get()
                ->all()),
            [],
            'public',
        );

        $attempt->forceFill(['state' => $state, 'lease_expires_at' => $leaseExpiresAt])->save();

        return $attempt;
    }

    /** @return array{HistoricImportOperation, Sermon} */
    private function quarantinedBatch(?string $operationId = null): array
    {
        $operation = HistoricImportOperation::query()->create([
            'operation_id' => $operationId ?? 'historic-'.str_repeat('a', 32),
            'binding_hash' => hash('sha256', $operationId ?? 'first'),
            'batch_key' => 'historic-release-ownership',
            'manifest_hashes' => ['video' => str_repeat('c', 64)],
            'plan_hash' => str_repeat('d', 64),
            'target_fingerprint' => str_repeat('e', 64),
            'runtime_fingerprint' => str_repeat('f', 64),
            'notification_mode' => 'external_disabled',
            'max_cost_minor_units' => 100,
        ]);

        $sermon = Sermon::factory()->create([
            'date' => '2026-01-04',
            'service' => SermonService::Morning,
            'slug' => 'release-ownership-'.Str::lower(Str::random(8)),
            'publication_state' => SermonPublicationState::Quarantined,
            'asset_disk' => 'historic_quarantine',
            'historic_import_operation_id' => $operation->id,
            'audio_file_path' => self::AudioPath,
            'video_file_path' => null,
            'transcript_file_path' => null,
            'thumbnail_file_path' => null,
        ]);

        Storage::disk('historic_quarantine')->put(self::AudioPath, self::AudioBytes);

        return [$operation, $sermon];
    }
}
