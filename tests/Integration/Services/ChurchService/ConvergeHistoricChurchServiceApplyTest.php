<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Actions\IngestChurchServiceSourceRevision;
use App\Data\ChurchServiceSourceRevision;
use App\Enums\ChurchServiceEvidenceKind;
use App\Enums\ChurchServiceSource;
use App\Jobs\ReconcileServiceSections;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\ChurchServiceReviewSession;
use App\Models\ChurchServiceSourceRecord;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\User;
use App\Services\ChurchService\ChurchServiceAssertionNormalizer;
use App\Services\ChurchService\ChurchServiceConvergenceAuditor;
use App\Services\ChurchService\ChurchServiceConvergenceBundleExporter;
use App\Services\ChurchService\ChurchServiceEvidenceSet;
use App\Services\ChurchService\ChurchServiceProjectionPersister;
use App\Services\ChurchService\ChurchServiceProjector;
use App\Services\ChurchService\ConvergeHistoricChurchService;
use App\Services\ChurchService\HistoricConvergenceLedger;
use App\Services\HistoricMedia\HistoricProcessingResultBundle;
use App\Services\HistoricMedia\HistoricProcessingResultBundleImporter;
use App\Services\HistoricMedia\HistoricProcessingResultInventory;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * WP6 acceptance for the historic convergence operation.
 *
 * Every case here runs the real importers, persisters and asset transfer. The
 * corpus is built by ingesting the evidence a local machine would have produced,
 * capturing the reviewed result, and then rolling production back to the state a
 * production database is actually in before the import — machine evidence only,
 * no Livestream revision and no run. That is what makes the apply path under
 * test the same one production would take.
 */
class ConvergeHistoricChurchServiceApplyTest extends TestCase
{
    use RefreshDatabase;

    private string $ledgerPath;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('historic_staging');
        Storage::fake('local');
        config()->set('media-processing.storage.historic_staging_disk', 'historic_staging');
        config()->set('media-processing.storage.sermon_disk', 'local');
        config()->set('app.release_identifier', 'test-release');

        $path = tempnam(sys_get_temp_dir(), 'crockenhill-apply-ledger-');
        self::assertIsString($path);
        $this->ledgerPath = $path;
        $this->app->instance(HistoricConvergenceLedger::class, new HistoricConvergenceLedger($path));
    }

    protected function tearDown(): void
    {
        if (is_file($this->ledgerPath)) {
            unlink($this->ledgerPath);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_applies_the_approved_plan_and_reruns_as_an_exact_no_op(): void
    {
        [$mediaBundle, $convergenceBundle] = $this->corpus();
        $converge = app(ConvergeHistoricChurchService::class);
        $plan = $converge->prepare($mediaBundle, $convergenceBundle);

        $result = $converge->execute($mediaBundle, $convergenceBundle, 0, 0, $plan->planHash, $plan);

        $this->assertSame(
            $convergenceBundle['services'][0]['resulting_canonical_hash'],
            $result['church_service']->canonical_hash,
        );
        $this->assertSame('imported-run', $result['processing_log']->processing_id);
        $this->assertSame(
            $result['church_service']->id,
            $result['processing_log']->church_service_id,
        );
        Storage::disk('local')->assertExists('service-transcripts/imported-run/audio.mp3');

        /** The whole operation reruns as an exact no-op on both halves. */
        $rerun = $converge->prepare($mediaBundle, $convergenceBundle);
        $summary = $rerun->services[0]['summary'];

        $this->assertSame('already_present', $summary['media_classification']);
        $this->assertSame('already_present', $summary['convergence_classification']);

        $converge->execute($mediaBundle, $convergenceBundle, 0, 0, $rerun->planHash, $rerun);

        $this->assertSame(1, MediaProcessingLog::query()->where('processing_id', 'imported-run')->count());
        $this->assertSame(
            $convergenceBundle['services'][0]['resulting_canonical_hash'],
            ChurchService::query()->sole()->canonical_hash,
        );
    }

    #[Test]
    public function the_closeout_audit_is_exact_over_both_bundles_and_needs_no_staging_disk(): void
    {
        [$mediaBundle, $convergenceBundle] = $this->corpus();
        app(ConvergeHistoricChurchService::class)->execute($mediaBundle, $convergenceBundle, 0, 0);

        /** Closeout runs on a production host, where the private staging disk is not mounted. */
        Storage::fake('historic_staging');

        $report = app(ChurchServiceConvergenceAuditor::class)->audit($convergenceBundle, $mediaBundle);

        $this->assertTrue($report['passed'], json_encode($report['services'][0]['differences'] ?? []));
        $this->assertSame(1, $report['totals']['passed']);
    }

    /**
     * A media-graph drift has to name the field that moved. An audit that only
     * reported "the hashes differ" would leave the operator with nothing to act
     * on at the one moment the detail matters.
     */
    #[Test]
    public function the_closeout_audit_reports_an_item_level_media_difference(): void
    {
        [$mediaBundle, $convergenceBundle] = $this->corpus();
        app(ConvergeHistoricChurchService::class)->execute($mediaBundle, $convergenceBundle, 0, 0);

        MediaProcessingLog::query()->where('processing_id', 'imported-run')->update(['duration' => 42.5]);

        $report = app(ChurchServiceConvergenceAuditor::class)->audit($convergenceBundle, $mediaBundle);
        $paths = array_column($report['services'][0]['differences'], 'path');
        $duration = collect($report['services'][0]['differences'])
            ->firstWhere('path', 'media_graph.run.duration');

        $this->assertFalse($report['passed']);
        $this->assertContains('media_graph.run.duration', $paths, implode(', ', $paths));
        $this->assertSame(60.0, $duration['expected']);
        $this->assertSame(42.5, $duration['actual']);
    }

    #[Test]
    public function a_dry_run_changes_no_row_and_no_destination_asset(): void
    {
        [$mediaBundle, $convergenceBundle] = $this->corpus();
        $service = ChurchService::query()->sole();
        $before = [
            'canonical_hash' => $service->canonical_hash,
            'canonical_revision' => $service->canonical_revision,
            'items' => $service->items()->count(),
            'source_records' => $service->sourceRecords()->count(),
            'updated_at' => $service->updated_at?->toISOString(),
        ];
        $converge = app(ConvergeHistoricChurchService::class);

        $converge->prepare($mediaBundle, $convergenceBundle);
        $converge->prepareBatch($mediaBundle, $convergenceBundle);

        $after = ChurchService::query()->sole();

        $this->assertSame($before, [
            'canonical_hash' => $after->canonical_hash,
            'canonical_revision' => $after->canonical_revision,
            'items' => $after->items()->count(),
            'source_records' => $after->sourceRecords()->count(),
            'updated_at' => $after->updated_at?->toISOString(),
        ]);
        $this->assertSame(0, MediaProcessingLog::query()->count());
        $this->assertSame(0, ServiceSection::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    #[Test]
    public function apply_fails_and_rolls_back_when_the_operation_queues_a_job(): void
    {
        [$mediaBundle, $convergenceBundle] = $this->corpus();
        $converge = app(ConvergeHistoricChurchService::class);
        $plan = $converge->prepare($mediaBundle, $convergenceBundle);

        MediaProcessingLog::created(function (MediaProcessingLog $log): void {
            ReconcileServiceSections::dispatch($log, ChurchService::query()->sole())->onQueue('livestream-processing');
        });

        try {
            $converge->execute($mediaBundle, $convergenceBundle, 0, 0, $plan->planHash, $plan);
            $this->fail('The queued job did not fail the apply.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('attempted to dispatch a queued job', $exception->getMessage());
        }

        $this->assertDatabaseMissing('media_processing_logs', ['processing_id' => 'imported-run']);
        Storage::disk('local')->assertMissing('service-transcripts/imported-run/audio.mp3');
        $this->assertSame(
            'persist_media_graph',
            $this->ledgerEntries('failed')[0]['phase'],
        );
    }

    /**
     * Every phase after the first write must roll the whole service back — rows
     * and the assets already copied — and say in the ledger how far it got.
     */
    #[Test]
    public function a_failure_in_any_phase_rolls_back_rows_assets_and_names_the_phase(): void
    {
        [$mediaBundle, $convergenceBundle] = $this->corpus();
        $failures = [
            'link_run_to_service' => function (object $armed): void {
                MediaProcessingLog::created(function (MediaProcessingLog $log) use ($armed): void {
                    if (! $armed->active || $log->processing_id !== 'imported-run') {
                        return;
                    }

                    $armed->active = false;
                    $other = ChurchService::factory()->create(['date' => '2019-01-06', 'service' => 'evening']);
                    MediaProcessingLog::query()
                        ->whereKey($log->id)
                        ->update(['church_service_id' => $other->id]);
                });
            },
            'resolve_portable_sections' => function (object $armed): void {
                ServiceSection::created(function (ServiceSection $section) use ($armed): void {
                    if (! $armed->active) {
                        return;
                    }

                    $armed->active = false;
                    ServiceSection::query()->whereKey($section->id)->update(['section_order' => 99]);
                });
            },
            'verify_media_graph' => function (object $armed): void {
                ChurchServiceItem::created(function () use ($armed): void {
                    if (! $armed->active) {
                        return;
                    }

                    $armed->active = false;
                    MediaProcessingLog::query()
                        ->where('processing_id', 'imported-run')
                        ->update(['duration' => 999.0]);
                });
            },
        ];

        foreach ($failures as $expectedPhase => $arm) {
            $armed = (object) ['active' => true];
            $arm($armed);

            try {
                app(ConvergeHistoricChurchService::class)->execute($mediaBundle, $convergenceBundle, 0, 0);
                $this->fail("The {$expectedPhase} sabotage did not fail the apply.");
            } catch (RuntimeException $exception) {
                $this->assertStringNotContainsString('did not fail the apply', $exception->getMessage());
            } finally {
                $armed->active = false;
            }

            $this->assertDatabaseMissing('media_processing_logs', ['processing_id' => 'imported-run']);
            Storage::disk('local')->assertMissing('service-transcripts/imported-run/audio.mp3');
            $this->assertSame($expectedPhase, $this->lastLedgerFailure()['phase']);
        }
    }

    #[Test]
    public function a_blocked_service_aborts_the_batch_before_the_applicable_one_is_written(): void
    {
        [$mediaBundle, $convergenceBundle] = $this->corpus(['2026-08-02', '2026-08-09']);

        /**
         * The second service's staged asset no longer matches its manifest, so
         * it cannot classify. The first service is perfectly applicable, so only
         * a batch-wide preflight keeps it unwritten.
         */
        Storage::disk('historic_staging')->put('historic/2026-08-09/audio.mp3', 'tampered');

        $this->expectException(RuntimeException::class);

        try {
            app(ConvergeHistoricChurchService::class)->executeBatch($mediaBundle, $convergenceBundle);
        } finally {
            $this->assertDatabaseMissing('media_processing_logs', ['processing_id' => 'imported-run-2026-08-02']);
            $this->assertDatabaseMissing('media_processing_logs', ['processing_id' => 'imported-run-2026-08-09']);
            Storage::disk('local')->assertMissing('service-transcripts/imported-run-2026-08-02/audio.mp3');
        }
    }

    #[Test]
    public function a_resume_skips_completed_services_and_revalidates_them(): void
    {
        [$mediaBundle, $convergenceBundle] = $this->corpus(['2026-08-02', '2026-08-09']);
        $converge = app(ConvergeHistoricChurchService::class);
        $this->stopTheBatchOnTheSecondService();

        try {
            $converge->executeBatch($mediaBundle, $convergenceBundle);
            $this->fail('The second service did not stop the batch.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Simulated hard failure', $exception->getMessage());
        }

        $this->assertSame(1, MediaProcessingLog::query()->where('processing_id', 'imported-run-2026-08-02')->count());
        $this->assertSame(0, MediaProcessingLog::query()->where('processing_id', 'imported-run-2026-08-09')->count());

        $results = $converge->executeBatch($mediaBundle, $convergenceBundle, null, true);

        $this->assertCount(1, $results);
        $this->assertSame('2026-08-09', $results[0]['church_service']->date->toDateString());
        $this->assertSame(1, MediaProcessingLog::query()->where('processing_id', 'imported-run-2026-08-02')->count());
        $this->assertSame(1, MediaProcessingLog::query()->where('processing_id', 'imported-run-2026-08-09')->count());
    }

    #[Test]
    public function a_resume_refuses_to_skip_a_completed_service_production_no_longer_holds(): void
    {
        [$mediaBundle, $convergenceBundle] = $this->corpus(['2026-08-02', '2026-08-09']);
        $converge = app(ConvergeHistoricChurchService::class);
        $this->stopTheBatchOnTheSecondService();

        try {
            $converge->executeBatch($mediaBundle, $convergenceBundle);
        } catch (RuntimeException) {
            // Expected: the batch stops after the first service commits.
        }

        /**
         * The ledger still says the first service applied, but production no
         * longer holds it. A resume that trusted the ledger would skip it and
         * leave the batch permanently half-applied.
         */
        MediaProcessingLog::query()->where('processing_id', 'imported-run-2026-08-02')->delete();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot revalidate the completed media result for 2026-08-02|morning');

        $converge->executeBatch($mediaBundle, $convergenceBundle, null, true);
    }

    /**
     * Fail the second service's apply once, the way a real hard failure stops a
     * batch: the first service is committed, the second is not, and the ledger
     * is what a resume has to reason from.
     */
    private function stopTheBatchOnTheSecondService(): void
    {
        $armed = (object) ['active' => true];
        MediaProcessingLog::created(function (MediaProcessingLog $log) use ($armed): void {
            if ($armed->active && $log->processing_id === 'imported-run-2026-08-09') {
                $armed->active = false;

                throw new RuntimeException('Simulated hard failure on the second service.');
            }
        });
    }

    #[Test]
    public function the_apply_holds_the_natural_identity_lock_for_the_whole_transaction(): void
    {
        [$mediaBundle, $convergenceBundle] = $this->corpus();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        app(ConvergeHistoricChurchService::class)->execute($mediaBundle, $convergenceBundle, 0, 0);

        $locked = array_values(array_filter(
            $queries,
            fn (string $sql): bool => str_contains($sql, 'church_services') && str_contains($sql, 'for update'),
        ));

        $this->assertNotSame([], $locked, 'The natural identity was never locked for update.');
    }

    #[Test]
    public function the_token_is_invalidated_by_a_source_bundle_asset_or_storage_change(): void
    {
        [$mediaBundle, $convergenceBundle] = $this->corpus();
        $converge = app(ConvergeHistoricChurchService::class);
        $baseline = $converge->prepare($mediaBundle, $convergenceBundle, 0, 0, 'fixed-operation', '2099-01-01T00:00:00+00:00');

        /** A production source revision that was not in the approved dry run. */
        $service = ChurchService::query()->sole();
        app(IngestChurchServiceSourceRevision::class)->execute($service, new ChurchServiceSourceRevision(
            source: ChurchServiceSource::OpenLp,
            sourceKey: 'openlp-late-arrival',
            inputHash: str_repeat('9', 64),
            assertions: app(ChurchServiceAssertionNormalizer::class)->normalize([[
                'position' => 1,
                'type' => 'custom',
                'title' => 'Late arrival',
            ]], ChurchServiceEvidenceKind::Planned),
            processingFingerprint: ['version' => 1],
        ));
        $sourceChanged = $converge->prepare($mediaBundle, $convergenceBundle, 0, 0, 'fixed-operation', '2099-01-01T00:00:00+00:00');

        $this->assertNotSame($baseline->planHash, $sourceChanged->planHash);
        $this->assertNotSame($baseline->contentHash, $sourceChanged->contentHash);

        /** A storage change: the same operation against a different destination disk. */
        Storage::fake('other_production');
        config()->set('media-processing.storage.sermon_disk', 'other_production');
        $storageChanged = $converge->prepare($mediaBundle, $convergenceBundle, 0, 0, 'fixed-operation', '2099-01-01T00:00:00+00:00');
        config()->set('media-processing.storage.sermon_disk', 'local');

        $this->assertNotSame($sourceChanged->planHash, $storageChanged->planHash);

        /** A bundle change. */
        $otherBundle = $convergenceBundle;
        $otherBundle['bundle_hash'] = str_repeat('7', 64);

        $this->expectException(RuntimeException::class);
        $converge->prepare($mediaBundle, $otherBundle, 0, 0, 'fixed-operation', '2099-01-01T00:00:00+00:00');
    }

    #[Test]
    public function an_asset_change_invalidates_the_token(): void
    {
        [$mediaBundle, $convergenceBundle] = $this->corpus();
        $converge = app(ConvergeHistoricChurchService::class);
        $converge->prepare($mediaBundle, $convergenceBundle, 0, 0, 'fixed-operation', '2099-01-01T00:00:00+00:00');

        Storage::disk('historic_staging')->put('historic/2026-08-02/audio.mp3', 'tampered');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('differs from its manifest');

        $converge->prepare($mediaBundle, $convergenceBundle, 0, 0, 'fixed-operation', '2099-01-01T00:00:00+00:00');
    }

    /** @return array<string, mixed> */
    private function lastLedgerFailure(): array
    {
        $failures = $this->ledgerEntries('failed');

        self::assertNotSame([], $failures, 'The ledger recorded no failure.');

        return $failures[count($failures) - 1];
    }

    /** @return list<array<string, mixed>> */
    private function ledgerEntries(string $event): array
    {
        return array_values(array_filter(
            app(HistoricConvergenceLedger::class)->entries(),
            fn (array $entry): bool => $entry['event'] === $event,
        ));
    }

    /**
     * Build a matched Bundle A/Bundle B pair and leave production in the state
     * it is in before the import: machine evidence only.
     *
     * @param  list<string>  $dates
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function corpus(array $dates = ['2026-08-02']): array
    {
        $batchHash = str_repeat('6', 64);
        $fingerprint = ['pipeline_version' => 1];
        $reviewer = User::factory()->create(['email' => 'reviewer@example.com']);
        $mediaServices = [];
        $serviceIds = [];

        foreach ($dates as $date) {
            [$serviceId, $mediaService] = $this->buildService($date, $reviewer, count($dates) > 1, $batchHash, $fingerprint);
            $serviceIds[] = $serviceId;
            $mediaServices[] = $mediaService;
        }

        $mediaBundle = app(HistoricProcessingResultBundle::class)->make($batchHash, $fingerprint, $mediaServices);
        $convergenceBundle = app(ChurchServiceConvergenceBundleExporter::class)->export(
            $serviceIds,
            $batchHash,
            $mediaBundle['bundle_hash'],
            $fingerprint,
        );

        foreach ($serviceIds as $serviceId) {
            $this->rollProductionBack(ChurchService::query()->findOrFail($serviceId));
        }

        return [$mediaBundle, $convergenceBundle];
    }

    /**
     * A real Bundle A carries the inventory of a run the local machine holds,
     * not a hand-written approximation of it. Persist the seed graph on a
     * savepoint, take the inventory the exporter would have taken, then discard
     * the rows and destination copies so the corpus is still unimported.
     *
     * Only the asset paths are put back: the bundle references its staged
     * sources, and production allocates its own destinations on import. Paths
     * are excluded from both the logical hash and the audit, so restoring them
     * changes nothing either side compares.
     *
     * @param  array<string, mixed>  $seedGraph
     * @param  array<string, mixed>  $fingerprint
     * @return array<string, mixed>
     */
    private function probeGraph(
        array $seedGraph,
        string $stagedAudioPath,
        string $batchHash,
        array $fingerprint,
    ): array {
        $seed = [
            'date' => '2026-08-02',
            'service' => 'morning',
            'source_manifest_hash' => str_repeat('1', 64),
            'evidence_set_hash' => str_repeat('2', 64),
            'pre_review_hash' => str_repeat('3', 64),
            'media_graph' => $seedGraph,
            'livestream_source_revision' => [],
            'assets' => [[
                'role' => 'run_audio_file_path',
                'path' => $stagedAudioPath,
                'size' => strlen((string) Storage::disk('historic_staging')->get($stagedAudioPath)),
                'sha256' => hash('sha256', (string) Storage::disk('historic_staging')->get($stagedAudioPath)),
            ]],
        ];
        $probe = app(HistoricProcessingResultBundle::class)->make($batchHash, $fingerprint, [$seed]);
        $importer = app(HistoricProcessingResultBundleImporter::class);
        DB::beginTransaction();

        try {
            $plan = $importer->prepareService($probe);
            $result = $importer->persistPreparedService($plan, $plan->planHash);
            $graph = app(HistoricProcessingResultInventory::class)
                ->build($result['processing_log']->fresh() ?? $result['processing_log']);
        } finally {
            DB::rollBack();
            Storage::fake('local');
        }

        $graph['run']['audio_file_path'] = $stagedAudioPath;

        return $graph;
    }

    /**
     * @param  array<string, mixed>  $fingerprint
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function buildService(
        string $date,
        User $reviewer,
        bool $suffixRun,
        string $batchHash,
        array $fingerprint,
    ): array {
        $processingId = $suffixRun ? "imported-run-{$date}" : 'imported-run';
        $assetPath = "historic/{$date}/audio.mp3";
        Storage::disk('historic_staging')->put($assetPath, "audio-{$date}");
        $mediaGraph = $this->probeGraph(
            $this->mediaGraph($processingId, "{$processingId}:section:1:seed", $date),
            $assetPath,
            $batchHash,
            $fingerprint,
        );
        $sectionKey = $mediaGraph['sections'][0]['section_key'];
        $service = ChurchService::factory()->create([
            'date' => $date,
            'service' => 'morning',
            'canonical_revision' => 0,
            'canonical_hash' => null,
        ]);
        $normalizer = app(ChurchServiceAssertionNormalizer::class);
        $ingest = app(IngestChurchServiceSourceRevision::class);

        /** Machine evidence production already holds. */
        $emailAssertions = $normalizer->normalize([[
            'position' => 1,
            'type' => 'custom',
            'title' => 'Planned item',
        ]], ChurchServiceEvidenceKind::Planned);
        $ingest->execute($service, new ChurchServiceSourceRevision(
            source: ChurchServiceSource::Email,
            sourceKey: "email-{$date}",
            inputHash: CanonicalJson::hash($emailAssertions),
            assertions: $emailAssertions,
            processingFingerprint: ['version' => 1],
        ));

        /** Livestream evidence only the local machine has, as Bundle A will carry it. */
        $livestreamAssertions = array_map(
            fn (array $assertion): array => [
                ...$assertion,
                'metadata' => ['livestream_processing_id' => $processingId],
            ],
            $normalizer->normalize([[
                'position' => 1,
                'type' => 'custom',
                'title' => 'Sung item',
            ]], ChurchServiceEvidenceKind::Observed),
        );
        $livestreamContent = ['summary' => null, 'notices' => [], 'chapter_markers' => []];
        $livestream = $ingest->execute($service, new ChurchServiceSourceRevision(
            source: ChurchServiceSource::Livestream,
            sourceKey: "livestream-{$processingId}",
            inputHash: CanonicalJson::hash($livestreamAssertions),
            assertions: $livestreamAssertions,
            processingFingerprint: ['format' => 'livestream-projection', 'version' => 1],
            serviceContent: $livestreamContent,
            batchHash: str_repeat('6', 64),
            payloadComplete: true,
        ));
        $service = $service->fresh() ?? $service;
        $preReviewHash = (string) $service->canonical_hash;
        $evidenceSetHash = app(ChurchServiceEvidenceSet::class)->hash($service->sourceRecords);

        /** The human review that Bundle B carries. */
        $manualAssertions = $normalizer->normalize([[
            'position' => 1,
            'type' => 'custom',
            'title' => 'Reviewed title',
        ]], ChurchServiceEvidenceKind::Manual);
        $manual = $ingest->execute($service, new ChurchServiceSourceRevision(
            source: ChurchServiceSource::Manual,
            sourceKey: "review:portable-review-{$date}",
            inputHash: CanonicalJson::hash($manualAssertions),
            assertions: $manualAssertions,
            processingFingerprint: ['format' => 'manual-review', 'version' => 1],
            serviceContent: ['summary' => null, 'notices' => [], 'chapter_markers' => []],
            createdByUserId: $reviewer->id,
        ));
        $service = $service->fresh() ?? $service;
        $service->forceFill(['reviewed_canonical_revision' => $service->canonical_revision])->saveQuietly();
        ChurchServiceReviewSession::factory()->create([
            'church_service_id' => $service->id,
            'review_uuid' => "portable-review-{$date}",
            'base_canonical_revision' => $service->canonical_revision - 1,
            'base_canonical_hash' => $preReviewHash,
            'included_proposal_ids' => [],
            'proposal_dispositions' => [],
            'manual_source_record_id' => $manual->sourceRecord->id,
            'resulting_canonical_revision' => $service->canonical_revision,
            'resulting_canonical_hash' => $service->canonical_hash,
            'reviewed_by_user_id' => $reviewer->id,
            'completed_at' => now(),
        ]);

        return [$service->id, [
            'date' => $date,
            'service' => 'morning',
            'source_manifest_hash' => str_repeat('1', 64),
            'evidence_set_hash' => $evidenceSetHash,
            'pre_review_hash' => $preReviewHash,
            'media_graph' => $mediaGraph,
            'livestream_source_revision' => [
                'source_key' => "livestream-{$processingId}",
                'revision_hash' => $livestream->sourceRecord->revision_hash,
                'input_hash' => $livestream->sourceRecord->input_hash,
                'batch_hash' => $livestream->sourceRecord->batch_hash,
                'processing_fingerprint' => ['format' => 'livestream-projection', 'version' => 1],
                'service_content' => $livestreamContent,
                'payload_complete' => true,
                'captured_at' => $livestream->sourceRecord->captured_at?->toISOString(),
                /**
                 * Bundle A carries the portable form: no local song identity,
                 * and the section referenced by its portable key. The importer
                 * re-resolves both against the run it has just created.
                 */
                'assertions' => array_map(
                    function (array $assertion) use ($sectionKey): array {
                        unset($assertion['song_id']);

                        return [
                            ...$assertion,
                            'metadata' => ['livestream_service_section_key' => $sectionKey],
                        ];
                    },
                    $livestreamAssertions,
                ),
            ],
            'assets' => [[
                'role' => 'run_audio_file_path',
                'path' => $assetPath,
                'size' => strlen("audio-{$date}"),
                'sha256' => hash('sha256', "audio-{$date}"),
            ]],
        ]];
    }

    /** @return array<string, mixed> */
    private function mediaGraph(string $processingId, string $sectionKey, string $date): array
    {
        return [
            'processing_key' => $processingId,
            'run' => [
                'processing_id' => $processingId,
                'processing_type' => 'livestream',
                'status' => 'completed',
                'current_step' => 'completed',
                'original_filename' => "{$date}.mp4",
                'file_hash' => hash('sha256', $processingId),
                'file_size' => 5,
                'duration' => 60.0,
                'extracted_date' => $date,
                'extracted_service' => 'morning',
                'audio_file_path' => "historic/{$date}/audio.mp3",
                'video_file_path' => null,
                'transcript_file_path' => null,
                'rms_log_path' => null,
                'sermon_start_time' => 10.0,
                'sermon_end_time' => 50.0,
                'threshold_method' => 'rms',
                'adaptive_threshold' => 0.2,
                'rms_stats' => [],
                'started_at' => "{$date}T10:00:00+00:00",
                'completed_at' => "{$date}T11:00:00+00:00",
                'is_degraded_completion' => false,
            ],
            'steps' => [[
                'step' => 'transcription',
                'status' => 'completed',
                'message' => null,
                'started_at' => "{$date}T10:00:00+00:00",
                'completed_at' => "{$date}T10:30:00+00:00",
            ]],
            'segments' => [[
                'segment_key' => "{$processingId}:segment:4",
                'segment_index' => 4,
                'start_time' => 0.0,
                'end_time' => 60.0,
                'duration' => 60.0,
                'classification' => 'speech',
                'avg_rms' => 0.2,
                'peak_rms' => 0.4,
                'is_sermon_candidate' => true,
                'is_sermon_segment' => true,
                'segment_order' => 1,
                'metadata' => [],
            ]],
            'sections' => [[
                'section_key' => $sectionKey,
                'section_order' => 1,
                'section_type' => 'sermon',
                'title' => 'Sermon',
                'summary' => null,
                'start_time' => 0.0,
                'end_time' => 60.0,
                'duration' => 60.0,
                'confidence' => 0.9,
                'status' => 'identified',
                'needs_manual_review' => false,
                'source_segment_keys' => ["{$processingId}:segment:4"],
                'song_match_type' => null,
                'publication_status' => 'not_applicable',
                'extracted_video_path' => null,
                'extracted_audio_path' => null,
                'published_at' => null,
            ]],
            'publications' => [],
            'song_videos' => [],
            'metadata' => [],
            'logical_hash' => str_repeat('b', 64),
        ];
    }

    /**
     * Return the service to the shape a production database holds before the
     * import: the machine evidence it always had, no Livestream revision, no
     * review and no run.
     */
    private function rollProductionBack(ChurchService $service): void
    {
        $service->reviewSessions()->delete();

        $removable = $service->sourceRecords()
            ->whereIn('source', [ChurchServiceSource::Manual->value, ChurchServiceSource::Livestream->value])
            ->get();

        foreach ($removable as $record) {
            $record->assertions()->delete();
            $record->delete();
        }

        $service->items()->delete();
        $service->forceFill([
            'canonical_revision' => 0,
            'canonical_hash' => null,
            'reviewed_canonical_revision' => null,
            'needs_review' => false,
        ])->saveQuietly();

        $records = $service->sourceRecords()->with(['assertions', 'assertions.sourceRecord'])->get();
        app(ChurchServiceProjectionPersister::class)->apply(
            $service,
            app(ChurchServiceProjector::class)->project($records),
        );

        self::assertSame(
            0,
            ChurchServiceSourceRecord::query()
                ->where('church_service_id', $service->id)
                ->where('source', ChurchServiceSource::Livestream->value)
                ->count(),
        );
    }
}
