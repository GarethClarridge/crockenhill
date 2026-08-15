<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Enums\ChurchServiceSource;
use App\Enums\HistoricImportOperationState;
use App\Enums\SermonPublicationState;
use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\HistoricImportOperation;
use App\Models\HistoricImportReleaseAsset;
use App\Models\HistoricImportReleaseAttempt;
use App\Models\Sermon;
use App\Models\Song;
use App\Models\SongUsageReport;
use App\Models\SongVideo;
use App\Services\Import\HistoricImportTargetFingerprint;
use App\Services\Public\PublicSongUsageService;
use App\Services\Public\SermonRepository;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

/**
 * The F29 release exercise: an approved item becomes public through its own
 * authority, without re-importing or changing provenance.
 */
class HistoricSermonReleaseBatchTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    private const SigningKey = 'release-test-key';

    /** @var list<string> */
    private array $authorisationPaths = [];

    /** @var list<string> */
    private array $artifactRoots = [];

    protected function setUp(): void
    {
        parent::setUp();

        Config::set([
            'church.services.public_from' => '2000-01-01',
            'media-processing.storage.historic_quarantine_disk' => 'historic_quarantine',
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.storage.transcript_disk' => 'public',
            'thumbnail-generation.storage.disk' => 'public',
            'media-processing.historic_import.evidence_signing_key' => self::SigningKey,
            'podcast.cache.enabled' => false,
        ]);
        Storage::fake('historic_quarantine');
        Storage::fake('public');
        // Redis is shared across parallel workers; a warm public listing here
        // would otherwise be read by another worker's page test.
        Cache::flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->authorisationPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        foreach ($this->artifactRoots as $root) {
            File::deleteDirectory($root);
        }

        parent::tearDown();
    }

    #[Test]
    public function an_authorised_batch_publishes_its_exact_records_and_their_assets(): void
    {
        $operation = $this->completedOperation();
        $sermon = $this->quarantinedSermon($operation);
        $songVideo = $this->quarantinedSongVideo($operation);
        $path = $this->authorisation($operation, [$sermon->id], [$songVideo->id]);

        $this->artisan('historic-import:release-batch', ['authorisation' => $path])
            ->assertSuccessful();

        $sermon->refresh();
        $songVideo->refresh();

        $this->assertSame(SermonPublicationState::Published, $sermon->publication_state);
        $this->assertSame('public', $sermon->asset_disk);
        $this->assertSame(SermonPublicationState::Published, $songVideo->publication_state);
        $this->assertSame($operation->id, $sermon->historic_import_operation_id);

        Storage::disk('public')->assertExists((string) $sermon->audio_file_path);
        Storage::disk('public')->assertExists((string) $sermon->transcript_file_path);
        Storage::disk('public')->assertExists($songVideo->video_file_path);

        $this->assertTrue(
            app(SermonRepository::class)->publicSermonQuery()->whereKey($sermon)->exists(),
        );

        foreach (['release_batch_started', 'sermon_released', 'song_video_released', 'release_batch_completed'] as $event) {
            $this->assertDatabaseHas('historic_import_journal_entries', [
                'historic_import_operation_id' => $operation->id,
                'event' => $event,
            ]);
        }

        $this->assertDatabaseHas('historic_import_artifacts', [
            'historic_import_operation_id' => $operation->id,
            'artifact_key' => 'release-batch-batch-one',
        ]);
    }

    /**
     * F61: the date-only hymn lane reaches the public occurrence union through the same signed
     * batch, and through nothing else. It owns no bytes, so this also proves a release whose
     * membership claims no destination still commits, journals and reports.
     */
    #[Test]
    public function an_authorised_batch_publishes_quarantined_song_usage_reports(): void
    {
        $operation = $this->completedOperation();
        $song = Song::factory()->create();
        $report = SongUsageReport::factory()->quarantined()->create([
            'song_id' => $song->id,
            'used_on' => '2007-06-17',
            'historic_import_operation_id' => $operation->id,
        ]);
        $publicUsage = app(PublicSongUsageService::class);

        $this->assertSame(0, $publicUsage->statsForSong($song)['usage_count']);

        $path = $this->authorisation($operation, [], [], songUsageReportIds: [$report->id]);

        $this->artisan('historic-import:release-batch', ['authorisation' => $path])
            ->expectsOutputToContain('1 song usage reports')
            ->assertSuccessful();

        $this->assertSame(
            SermonPublicationState::Published,
            $report->refresh()->publication_state,
        );
        $this->assertSame(1, $publicUsage->statsForSong($song)['usage_count']);

        $this->assertDatabaseHas('historic_import_journal_entries', [
            'historic_import_operation_id' => $operation->id,
            'event' => 'song_usage_report_released',
        ]);
        $this->assertSame(0, HistoricImportReleaseAsset::query()->count());
    }

    /** F61: an unreleased report stays out of public history however the batch fails. */
    #[Test]
    public function an_unsigned_batch_leaves_song_usage_reports_quarantined(): void
    {
        $operation = $this->completedOperation();
        $song = Song::factory()->create();
        $report = SongUsageReport::factory()->quarantined()->create([
            'song_id' => $song->id,
            'historic_import_operation_id' => $operation->id,
        ]);
        $path = $this->authorisation($operation, [], [], sign: false, songUsageReportIds: [$report->id]);

        $this->artisan('historic-import:release-batch', ['authorisation' => $path])
            ->expectsOutputToContain('signature is invalid')
            ->assertFailed();

        $this->assertSame(
            SermonPublicationState::Quarantined,
            $report->refresh()->publication_state,
        );
        $this->assertSame(0, app(PublicSongUsageService::class)->statsForSong($song)['usage_count']);
    }

    /**
     * The gate that makes release "separate": closeout, not the import approval.
     * An operation still mid-flight has not passed its exact audit or no-op
     * rerun, so nothing it produced may become public yet.
     */
    #[Test]
    public function a_batch_is_refused_until_its_operation_passes_exact_closeout(): void
    {
        $operation = $this->completedOperation(HistoricImportOperationState::CloseoutRequired);
        $sermon = $this->quarantinedSermon($operation);
        $path = $this->authorisation($operation, [$sermon->id], []);

        $this->artisan('historic-import:release-batch', ['authorisation' => $path])
            ->expectsOutputToContain('exact closeout')
            ->assertFailed();

        $this->assertQuarantineIntact($sermon);
    }

    /**
     * REV-D2's third tier. "Existence widens" put identity-corroborated email evidence into the
     * service graph unattended and left it unreviewed; the same decision requires that a service
     * carrying such evidence is not release-eligible, or the evidence tier would have become a
     * route to publication.
     */
    #[Test]
    public function a_batch_is_refused_while_a_service_carries_unfinalised_email_evidence(): void
    {
        $operation = $this->completedOperation();
        $sermon = $this->quarantinedSermon($operation);

        ChurchService::factory()->create([
            'date' => $sermon->date->toDateString(),
            'service' => $sermon->service,
            'needs_review' => true,
            'import_metadata' => [
                'email_evidence' => ['message-1|morning:2026-01-04' => ['finalised' => false]],
            ],
        ])->sourceRecords()->create([
            'source' => ChurchServiceSource::Email,
            'source_key' => 'message-1|morning:2026-01-04',
            'revision_hash' => str_repeat('a', 64),
            'input_hash' => str_repeat('b', 64),
            'processing_fingerprint' => ['format' => 'email-plan', 'version' => 1],
            'captured_at' => now(),
        ]);

        $path = $this->authorisation($operation, [$sermon->id], []);

        $this->artisan('historic-import:release-batch', ['authorisation' => $path])
            // One expectation, not two: each consumes the matching line, and the refusal names
            // the offending services on the same line as its reason.
            ->expectsOutputToContain('unreviewed, unfinalised email evidence: 2026-01-04 morning.')
            ->assertFailed();

        $this->assertQuarantineIntact($sermon);
    }

    #[Test]
    public function a_batch_is_refused_when_its_signature_does_not_verify(): void
    {
        $operation = $this->completedOperation();
        $sermon = $this->quarantinedSermon($operation);
        $path = $this->authorisation($operation, [$sermon->id], [], sign: false);

        $this->artisan('historic-import:release-batch', ['authorisation' => $path])
            ->expectsOutputToContain('signature is invalid')
            ->assertFailed();

        $this->assertQuarantineIntact($sermon);
    }

    #[Test]
    public function a_batch_is_refused_after_its_authorisation_expires(): void
    {
        $operation = $this->completedOperation();
        $sermon = $this->quarantinedSermon($operation);
        $path = $this->authorisation($operation, [$sermon->id], [], overrides: [
            'expires_at' => now()->subMinute()->toIso8601String(),
            'observation_ends_at' => now()->addDay()->toIso8601String(),
        ]);

        $this->artisan('historic-import:release-batch', ['authorisation' => $path])
            ->expectsOutputToContain('has expired')
            ->assertFailed();

        $this->assertQuarantineIntact($sermon);
    }

    #[Test]
    public function a_batch_is_refused_when_it_names_a_record_from_another_operation(): void
    {
        $operation = $this->completedOperation();
        $sermon = $this->quarantinedSermon($operation);
        $foreign = $this->quarantinedSermon($this->completedOperation());
        $path = $this->authorisation($operation, [$sermon->id, $foreign->id], []);

        $this->artisan('historic-import:release-batch', ['authorisation' => $path])
            ->expectsOutputToContain('membership is not exact')
            ->assertFailed();

        $this->assertQuarantineIntact($sermon);
        $this->assertQuarantineIntact($foreign);
    }

    /**
     * The counts are a second, independent statement of the same fact, so a
     * truncated id list cannot silently release a smaller batch than the one
     * that was signed. This matters more under D10, not less: with one operator
     * and no second reader, a machine check that the artifact disagrees with
     * itself is the only thing standing between a truncated file and a release.
     */
    #[Test]
    public function a_batch_is_refused_when_its_declared_counts_disagree_with_its_ids(): void
    {
        $operation = $this->completedOperation();
        $sermon = $this->quarantinedSermon($operation);
        $path = $this->authorisation($operation, [$sermon->id], [], overrides: [
            'declared_counts' => ['sermons' => 2, 'song_videos' => 0],
        ]);

        $this->artisan('historic-import:release-batch', ['authorisation' => $path])
            ->expectsOutputToContain('declared counts')
            ->assertFailed();

        $this->assertQuarantineIntact($sermon);
    }

    #[Test]
    public function a_batch_is_refused_when_it_authorises_a_different_deployed_release(): void
    {
        $operation = $this->completedOperation();
        $sermon = $this->quarantinedSermon($operation);
        $path = $this->authorisation($operation, [$sermon->id], [], overrides: [
            'release_identifier' => 'some-other-release',
        ]);

        $this->artisan('historic-import:release-batch', ['authorisation' => $path])
            ->expectsOutputToContain('deployed release')
            ->assertFailed();

        $this->assertQuarantineIntact($sermon);
    }

    /**
     * Rollback ownership that lapses with the authorisation is not rollback
     * ownership; §F.6 requires the observation period to outlive the batch.
     */
    #[Test]
    public function a_batch_is_refused_when_its_observation_window_ends_with_the_authorisation(): void
    {
        $operation = $this->completedOperation();
        $sermon = $this->quarantinedSermon($operation);
        $path = $this->authorisation($operation, [$sermon->id], [], overrides: [
            'observation_ends_at' => now()->addHour()->toIso8601String(),
            'expires_at' => now()->addHour()->toIso8601String(),
        ]);

        $this->artisan('historic-import:release-batch', ['authorisation' => $path])
            ->expectsOutputToContain('observation window')
            ->assertFailed();

        $this->assertQuarantineIntact($sermon);
    }

    /**
     * One missing byte aborts the whole batch. A half-released batch would put
     * public rows in front of assets that never arrived, which is the failure
     * F29 exists to prevent.
     *
     * HIR7 changed what "aborts" leaves behind. The batch used to delete by path
     * on failure, and that delete is the defect: at a final public path, the
     * bytes it removes may be a concurrent winner's. So an object this attempt
     * created and could not publish is **retained** and recorded `orphaned` for
     * a human to reconcile. What still has to hold — and is what F29 is actually
     * about — is that no record became public and every one stayed quarantined,
     * so nothing at that path is reachable.
     */
    #[Test]
    public function a_missing_quarantined_asset_leaves_the_whole_batch_unreleased(): void
    {
        $operation = $this->completedOperation();
        $intact = $this->quarantinedSermon($operation);
        $damaged = $this->quarantinedSermon($operation);
        Storage::disk('historic_quarantine')->delete((string) $damaged->audio_file_path);
        $path = $this->authorisation($operation, [$intact->id, $damaged->id], []);

        $this->artisan('historic-import:release-batch', ['authorisation' => $path])
            ->expectsOutputToContain('missing or unsafe')
            ->assertFailed();

        $this->assertQuarantineIntact($intact);
        $this->assertQuarantineIntact($damaged);
        $this->get(route('sermons.audio', $intact))->assertNotFound();

        $attempt = HistoricImportReleaseAttempt::query()->sole();
        $this->assertSame(HistoricImportReleaseAttempt::StateOrphaned, $attempt->state);
        $this->assertSame(
            [HistoricImportReleaseAsset::StateOrphaned, HistoricImportReleaseAsset::StateOrphaned],
            $attempt->assets()
                ->where('record_id', $intact->id)
                ->orderBy('destination_path')
                ->pluck('state')
                ->all(),
            'The bytes this attempt created are retained under an explicit orphan record, never deleted.',
        );
        $this->assertSame(
            0,
            $attempt->assets()->where('record_id', $damaged->id)->whereNotNull('published_at')->count(),
        );
    }

    #[Test]
    public function a_dry_run_verifies_the_authorisation_without_publishing(): void
    {
        $operation = $this->completedOperation();
        $sermon = $this->quarantinedSermon($operation);
        $path = $this->authorisation($operation, [$sermon->id], []);

        $this->artisan('historic-import:release-batch', ['authorisation' => $path, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertQuarantineIntact($sermon);
        $this->assertDatabaseMissing('historic_import_journal_entries', [
            'historic_import_operation_id' => $operation->id,
            'event' => 'release_batch_started',
        ]);
    }

    /**
     * D10: one maintainer signs, verifies and owns rollback for a release batch.
     * Reinstating a distinctness check on these three roles would make the only
     * person who can run this command unable to authorise it, so this test is
     * here to fail loudly if that check comes back.
     */
    #[Test]
    public function one_maintainer_may_hold_every_release_role(): void
    {
        $operation = $this->completedOperation();
        $sermon = $this->quarantinedSermon($operation);
        $path = $this->authorisation($operation, [$sermon->id], [], overrides: [
            'roles' => [
                'release_owner' => 'gareth',
                'independent_verifier' => 'gareth',
                'rollback_owner' => 'gareth',
            ],
        ]);

        $this->artisan('historic-import:release-batch', ['authorisation' => $path])
            ->assertSuccessful();

        $sermon->refresh();

        $this->assertSame(SermonPublicationState::Published, $sermon->publication_state);
    }

    /**
     * The roles may name one person but may not be blank: the artifact is still
     * the durable record of who owns rollback during the observation window.
     */
    #[Test]
    public function a_release_role_with_no_named_owner_is_refused(): void
    {
        $operation = $this->completedOperation();
        $sermon = $this->quarantinedSermon($operation);
        $path = $this->authorisation($operation, [$sermon->id], [], overrides: [
            'roles' => [
                'release_owner' => 'gareth',
                'independent_verifier' => 'gareth',
                'rollback_owner' => '',
            ],
        ]);

        $this->artisan('historic-import:release-batch', ['authorisation' => $path])
            ->assertFailed();

        $this->assertQuarantineIntact($sermon);
    }

    private function assertQuarantineIntact(Sermon $sermon): void
    {
        $sermon->refresh();

        $this->assertSame(SermonPublicationState::Quarantined, $sermon->publication_state);
        $this->assertSame('historic_quarantine', $sermon->asset_disk);
        $this->assertFalse(
            app(SermonRepository::class)->publicSermonQuery()->whereKey($sermon)->exists(),
        );
    }

    private function completedOperation(
        HistoricImportOperationState $state = HistoricImportOperationState::Complete,
    ): HistoricImportOperation {
        $operation = $this->createHistoricImportOperation(
            app(HistoricImportTargetFingerprint::class)->hash(),
            ['state' => $state],
        );
        $this->artifactRoots[] = storage_path('app/private/historic-import/'.$operation->operation_id);

        return $operation;
    }

    /**
     * Paths are keyed on the record, not just the operation.
     *
     * They used to be operation-scoped only, so two sermons in one batch named
     * the same public destination — and each `put()` overwrote the other's
     * bytes. HIR7's global destination uniqueness refuses that outright, and it
     * should: two records advertising one file means one of them is wrong.
     */
    private function quarantinedSermon(HistoricImportOperation $operation): Sermon
    {
        $sermon = Sermon::factory()->create([
            'date' => '2026-01-04',
            'service' => SermonService::Morning,
            'publication_state' => SermonPublicationState::Quarantined,
            'asset_disk' => 'historic_quarantine',
            'historic_import_operation_id' => $operation->id,
            'livestream_processing_id' => null,
        ]);
        $sermon->forceFill([
            'audio_file_path' => "sermons/{$operation->id}/{$sermon->id}/audio.mp3",
            'transcript_file_path' => "sermons/{$operation->id}/{$sermon->id}/transcript.md",
            'video_file_path' => null,
            'thumbnail_file_path' => null,
        ])->save();

        foreach (['audio_file_path', 'transcript_file_path'] as $field) {
            Storage::disk('historic_quarantine')->put(
                (string) $sermon->getAttribute($field),
                "{$field}-bytes-{$sermon->id}",
            );
        }

        return $sermon->refresh();
    }

    private function quarantinedSongVideo(HistoricImportOperation $operation): SongVideo
    {
        $songVideo = SongVideo::factory()->quarantined()->create([
            'historic_import_operation_id' => $operation->id,
        ]);
        $songVideo->forceFill([
            'video_file_path' => "song-videos/{$operation->id}/{$songVideo->id}/song.mp4",
        ])->save();
        Storage::disk('historic_quarantine')->put($songVideo->video_file_path, 'song-video-bytes');

        return $songVideo;
    }

    /**
     * @param  list<int>  $sermonIds
     * @param  list<int>  $songVideoIds
     * @param  array<string, mixed>  $overrides
     * @param  list<int>  $songUsageReportIds
     */
    private function authorisation(
        HistoricImportOperation $operation,
        array $sermonIds,
        array $songVideoIds,
        bool $sign = true,
        array $overrides = [],
        array $songUsageReportIds = [],
    ): string {
        $authorisation = [
            'format' => 'crockenhill-historic-release-authorisation',
            'version' => 1,
            'authorisation_id' => 'release-2026-08-10',
            'operation_id' => $operation->operation_id,
            'target_fingerprint' => $operation->target_fingerprint,
            'release_identifier' => config('app.release_identifier'),
            'expires_at' => now()->addHour()->toIso8601String(),
            'batch_key' => 'batch-one',
            'sermon_ids' => $sermonIds,
            'song_video_ids' => $songVideoIds,
            'song_usage_report_ids' => $songUsageReportIds,
            'declared_counts' => [
                'sermons' => count($sermonIds),
                'song_videos' => count($songVideoIds),
                'song_usage_reports' => count($songUsageReportIds),
            ],
            'roles' => [
                'release_owner' => 'person-one',
                'independent_verifier' => 'person-two',
                'rollback_owner' => 'person-three',
            ],
            'observation_ends_at' => now()->addWeek()->toIso8601String(),
            'signature' => ['algorithm' => 'hmac-sha256', 'key_id' => 'test-key', 'digest' => ''],
            ...$overrides,
        ];
        $authorisation['signature']['digest'] = $sign
            ? hash_hmac(
                'sha256',
                CanonicalJson::encode(array_diff_key($authorisation, ['signature' => true])),
                self::SigningKey,
            )
            : str_repeat('0', 64);

        $path = sys_get_temp_dir().'/historic-release-'.uniqid().'.json';
        file_put_contents($path, json_encode($authorisation, JSON_THROW_ON_ERROR));
        $this->authorisationPaths[] = $path;

        return $path;
    }
}
