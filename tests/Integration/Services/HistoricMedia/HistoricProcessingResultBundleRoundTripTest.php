<?php

declare(strict_types=1);

namespace Tests\Integration\Services\HistoricMedia;

use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\ChurchServiceItemAssertion;
use App\Models\ChurchServiceReviewSession;
use App\Models\ChurchServiceSourceRecord;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\SermonProcessingStep;
use App\Models\SermonScriptureFilter;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SongVideo;
use App\Services\HistoricMedia\HistoricProcessingFingerprint;
use App\Services\HistoricMedia\HistoricProcessingResultBundleExporter;
use App\Services\HistoricMedia\HistoricProcessingResultBundleImporter;
use App\Services\HistoricMedia\HistoricProcessingResultInventory;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\HistoricMedia\HistoricStagingGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\HistoricNormalOutputCanary;
use Tests\TestCase;

/**
 * G3/PR12. Every other Bundle A test either rebuilds the graph from models in
 * one database or drives the importer over a hand-authored bundle. Neither can
 * see local-primary-key coupling, because the ids never move.
 *
 * This exports the WP0 canary — the same fixture the contract gate is defined
 * against, not a second approximation of it — and imports it into a database
 * whose auto-increments have been deliberately shifted, so every row lands on a
 * primary key the source never used. §10.1 requires the logical hash to survive
 * that; §13.5 step 12 is where it would otherwise be discovered, against
 * production.
 */
class HistoricProcessingResultBundleRoundTripTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Far enough above the canary's own ids that a leaked source key cannot
     * coincidentally resolve to the equivalent imported row.
     */
    private const int PRIMARY_KEY_SHIFT = 5000;

    /** @var array<string, string> Everything the canary's persistence path wrote, by path. */
    private array $productionFiles = [];

    /**
     * Tables the import recreates. Every row here must land above the ids the
     * source used, or the primary keys never actually moved and the hash
     * equality below proves nothing.
     */
    private const array RECREATED_TABLES = [
        'media_processing_logs',
        'sermons',
        'service_sections',
        'livestream_segments',
        'song_videos',
        'sermon_processing_steps',
    ];

    /**
     * Tables the import resolves against rather than recreates. A preacher, a
     * song and a service are matched by natural key, so the correct outcome is
     * that the existing rows are reused on their original ids — the portable
     * graph referring to them by slug and canonical key is the whole point.
     */
    private const array RESOLVED_TABLES = [
        'preachers',
        'songs',
        'church_services',
        'church_service_items',
    ];

    #[Test]
    public function the_canary_round_trips_through_bundle_a_into_a_different_pk_database(): void
    {
        $canary = (new HistoricNormalOutputCanary)->createCanary();
        $run = $canary['run'];
        $bundle = $this->export($run);
        $service = $bundle['services'][0];

        /**
         * Read the source graph after export preparation, not before: pinning
         * the batch's processing fingerprint is itself durable metadata, so it
         * moves the logical hash. What must hold is that the bundle carries the
         * graph the run actually has at export time.
         */
        $sourceGraph = app(HistoricProcessingResultInventory::class)->build($run->fresh());
        $sourceHash = $sourceGraph['logical_hash'];
        $sourceIds = $this->currentIds();
        $sourceRoles = (new HistoricNormalOutputCanary)->assetRoles($sourceGraph);

        $this->assertSame(
            $sourceHash,
            $service['media_graph']['logical_hash'],
            'The exported bundle must carry the live graph, not a re-derived one.',
        );
        $this->assertSame(
            array_column($canary['manifest']['media_graph']['sections'], 'section_key'),
            array_column($sourceGraph['sections'], 'section_key'),
            'Export preparation must not have disturbed the canary graph itself.',
        );

        $this->stageExportedAssets($service['assets']);
        $this->tearDownSourceGraph();
        $this->shiftPrimaryKeys();
        $this->useDestinationHostDisks();

        $importer = app(HistoricProcessingResultBundleImporter::class);
        $plan = $importer->prepareService($bundle);

        $this->assertSame('create', $plan->classification, $plan->reason);

        $result = $importer->importService($bundle, $plan->planHash);
        $importedRun = $result['processing_log']->fresh();
        $importedGraph = app(HistoricProcessingResultInventory::class)->build($importedRun);

        /**
         * The gate itself. If any portable identity still folded in a local id,
         * shifting the auto-increments would have moved it.
         */
        $this->assertSame($sourceHash, $importedGraph['logical_hash']);

        $this->assertPrimaryKeysActuallyMoved($sourceIds);
        $this->assertNoFieldRelationshipOrRoleWasLost($sourceGraph, $importedGraph);
        $this->assertEveryAssetRoleSurvived(
            $sourceRoles,
            (new HistoricNormalOutputCanary)->assetRoles($importedGraph),
        );
    }

    /**
     * The importer must not silently accept a bundle whose graph disagrees with
     * the hash it travels under, or the equality above proves nothing.
     */
    #[Test]
    public function a_tampered_graph_does_not_survive_the_round_trip(): void
    {
        $canary = (new HistoricNormalOutputCanary)->createCanary();
        $bundle = $this->export($canary['run']);

        $this->stageExportedAssets($bundle['services'][0]['assets']);
        $this->tearDownSourceGraph();
        $this->shiftPrimaryKeys();
        $this->useDestinationHostDisks();

        $bundle['services'][0]['media_graph']['run']['sermon_start_time'] = 999.0;

        $this->expectException(RuntimeException::class);

        app(HistoricProcessingResultBundleImporter::class)->prepareService($bundle);
    }

    /**
     * Export the canary run to a Bundle A file and read it back as the importer
     * would receive it.
     *
     * @return array<string, mixed>
     */
    private function export(MediaProcessingLog $run): array
    {
        /**
         * Export happens on the processing host, where every media output disk
         * is the private staging disk — that is exactly what the staging guard
         * enforces before it will issue a context. The canary persisted its
         * assets to 'local', so 'local' is that host's staging disk here. The
         * import phase later flips production back to a distinct disk, which is
         * what the transfer requires of a destination host.
         */
        $this->useProcessingHostDisks();

        $stagingContext = app(HistoricStagingGuard::class)->contextForApprovedPlan(
            str_repeat('2', 64),
            str_repeat('1', 64),
        );

        /**
         * PR17 made the approved staging context a precondition of export. The
         * canary predates it and is built as a pure processing result, so the
         * context is attached here rather than baked into the shared fixture —
         * the serializer strips it from the portable projection either way, so
         * it cannot affect the logical hash this test turns on.
         */
        $metadata = $run->processing_metadata?->toArray() ?? [];
        $metadata['historic_import']['staging_context'] = $stagingContext->toArray();
        $metadata['processing_fingerprint'] = app(HistoricProcessingFingerprint::class)
            ->forStagingContext($stagingContext);
        $run->forceFill(['processing_metadata' => $metadata])->save();

        $this->markServiceExportable($run);

        /**
         * Activating a context re-roots the staging disk under the batch root,
         * which is where a genuinely staged run's media would already sit. The
         * canary wrote its output before any context existed, so carry it
         * across — capturing the contents first, because the paths resolve
         * somewhere else once the context is active.
         */
        $this->productionFiles = $this->readProductionFiles();

        File::ensureDirectoryExists(storage_path('scratch'));
        $path = storage_path('scratch/historic-bundle-a-round-trip.json');

        try {
            app(HistoricStagingContextRegistry::class)->within(
                $stagingContext,
                function () use ($run, $stagingContext, $path): void {
                    foreach ($this->productionFiles as $file => $contents) {
                        Storage::disk('local')->put($file, $contents);
                    }

                    app(HistoricProcessingResultBundleExporter::class)->export(
                        [$run->processing_id],
                        $stagingContext->manifestHash,
                        $path,
                    );
                },
            );

            /** @var array<string, mixed> $bundle */
            $bundle = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

            /**
             * Activating a context rewrites `filesystems.disks.*` and forgets
             * the disk, and `Storage::fake()` only swaps the resolved instance —
             * so restoring the context resolves a real disk and the fake is
             * gone. Re-establish it from the contents captured above, or the
             * production side of this test would be reading the developer's
             * actual storage directory.
             */
            Storage::fake('local');

            foreach ($this->productionFiles as $file => $contents) {
                Storage::disk('local')->put($file, $contents);
            }

            return $bundle;
        } finally {
            File::delete($path);
        }
    }

    /**
     * Every file the canary's real persistence path produced, by path.
     *
     * @return array<string, string>
     */
    private function readProductionFiles(): array
    {
        $files = [];

        foreach (Storage::disk('local')->allFiles() as $file) {
            $contents = Storage::disk('local')->get($file);

            if (is_string($contents)) {
                $files[$file] = $contents;
            }
        }

        return $files;
    }

    /**
     * Bundle A only exports a finally reviewed service backed by a complete
     * Livestream revision. The canary is a pure processing result, so give it
     * the review and evidence state a real historic run would have reached
     * before export — none of which is part of the media graph under test.
     */
    private function markServiceExportable(MediaProcessingLog $run): void
    {
        $service = $run->churchService;

        if (! $service instanceof ChurchService) {
            throw new RuntimeException('The canary run must belong to a church service.');
        }

        $service->forceFill([
            'canonical_hash' => str_repeat('9', 64),
            'canonical_revision' => 3,
            'reviewed_canonical_revision' => 3,
        ])->save();

        ChurchServiceReviewSession::factory()->create([
            'church_service_id' => $service->id,
            'base_canonical_hash' => str_repeat('8', 64),
            'completed_at' => now(),
        ]);

        $source = ChurchServiceSourceRecord::factory()->create([
            'church_service_id' => $service->id,
            'source' => ChurchServiceSource::Livestream,
            'source_key' => $run->processing_id.'|v1',
            'revision_hash' => str_repeat('7', 64),
            'input_hash' => str_repeat('6', 64),
            'payload_complete' => true,
            'processing_fingerprint' => ['format' => 'livestream-projection', 'version' => 1],
            'service_content' => ['summary' => 'Reviewed locally', 'notices' => [], 'chapter_markers' => []],
        ]);

        ChurchServiceItemAssertion::factory()->create([
            'source_record_id' => $source->id,
            'assertion_key' => $run->processing_id.':section:1',
        ]);
    }

    /**
     * Put every exported asset on the staging disk at the path the bundle
     * declares, which is what a production host would receive.
     *
     * @param  list<array{path: string, size: int, sha256: string, kind: string, roles: list<string>}>  $assets
     */
    private function stageExportedAssets(array $assets): void
    {
        foreach ($assets as $asset) {
            $contents = $this->productionFiles[$asset['path']] ?? null;

            if (! is_string($contents)) {
                throw new RuntimeException("Exported asset {$asset['path']} was never produced by the canary.");
            }

            Storage::disk('historic_staging')->put($asset['path'], $contents);
        }
    }

    /**
     * Remove the entire source graph and its production media, so the import is
     * a genuine create into an empty destination rather than a no-op.
     */
    private function tearDownSourceGraph(): void
    {
        MediaProcessingLog::query()
            ->where('processing_id', HistoricNormalOutputCanary::TARGET_PROCESSING_ID)
            ->firstOrFail();

        ChurchServiceItem::query()->update([
            'livestream_processing_id' => null,
            'livestream_service_section_id' => null,
        ]);
        ServiceSection::query()->update([
            'published_sermon_id' => null,
            'matched_item_id' => null,
            'expected_item_id' => null,
        ]);
        MediaProcessingLog::query()->update(['sermon_id' => null]);
        Sermon::query()->update(['livestream_processing_id' => null]);

        SongVideo::query()->delete();
        SermonScriptureFilter::query()->delete();
        Sermon::query()->delete();
        ServiceSection::query()->delete();
        LivestreamSegment::query()->delete();
        SermonProcessingStep::query()->delete();

        /**
         * Every run, not only the persisted one. The canary is built as one run
         * and re-persisted as another, and both carry the same source file hash
         * — leaving the first behind classifies the import as a `conflict`
         * against a fixture artefact rather than exercising a create.
         */
        MediaProcessingLog::query()->delete();

        Storage::fake('local');
    }

    /**
     * Move every table's next id well past the range the canary used. MySQL
     * keeps counting after a delete, so without this the imported rows could
     * land on ids that merely happen to differ; the shift makes the difference
     * deliberate and large.
     */
    private function shiftPrimaryKeys(): void
    {
        foreach ([...self::RECREATED_TABLES, ...self::RESOLVED_TABLES] as $table) {
            $next = ((int) DB::table($table)->max('id')) + self::PRIMARY_KEY_SHIFT;
            DB::statement("ALTER TABLE `{$table}` AUTO_INCREMENT = {$next}");
        }
    }

    /** @return array<string, int> */
    private function currentIds(): array
    {
        $ids = [];

        foreach ([...self::RECREATED_TABLES, ...self::RESOLVED_TABLES] as $table) {
            $ids[$table] = (int) DB::table($table)->max('id');
        }

        return $ids;
    }

    /**
     * Prove the shift did what it claims: the import must have allocated keys
     * above everything the source ever used, or this test is asserting equality
     * over a graph that never moved.
     *
     * @param  array<string, int>  $sourceIds
     */
    private function assertPrimaryKeysActuallyMoved(array $sourceIds): void
    {
        foreach (self::RECREATED_TABLES as $table) {
            $minimum = DB::table($table)->min('id');

            $this->assertNotNull($minimum, "The import must have recreated {$table}.");
            $this->assertGreaterThan(
                $sourceIds[$table],
                (int) $minimum,
                "Every imported {$table} row must sit above the ids the source used.",
            );
        }

        /**
         * The other half of the same property: natural-key resolution must
         * reuse what is already there instead of minting a duplicate on a
         * shifted id. A second 'canary-preacher' would mean the graph carried a
         * local identity after all.
         */
        foreach (self::RESOLVED_TABLES as $table) {
            $this->assertLessThanOrEqual(
                $sourceIds[$table],
                (int) DB::table($table)->max('id'),
                "The import duplicated {$table} instead of resolving it by natural key.",
            );
        }
    }

    /**
     * §10.1: the round trip must lose no field, relationship or asset role. The
     * logical hash covers the portable projection, so compare the graphs
     * structurally too — a collection silently emptied on both sides would hash
     * equal and still be a loss.
     *
     * Asset *paths* are deliberately excluded: `assetDestinations()` allocates a
     * distinct production path per role, so one shared file becomes one copy per
     * role. Roles and content identity survive; paths do not, by design.
     *
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $imported
     */
    private function assertNoFieldRelationshipOrRoleWasLost(array $source, array $imported): void
    {
        foreach (['run', 'metadata'] as $key) {
            $this->assertSame(
                array_keys($source[$key]),
                array_keys($imported[$key]),
                "The imported graph lost or gained {$key} fields.",
            );
        }

        foreach (['steps', 'segments', 'sections', 'publications', 'song_videos'] as $collection) {
            $this->assertNotEmpty(
                $source[$collection],
                "The canary must exercise {$collection} for this comparison to mean anything.",
            );
            $this->assertCount(
                count($source[$collection]),
                $imported[$collection],
                "The imported graph lost {$collection}.",
            );
        }

        $this->assertSame(
            array_column($source['sections'], 'section_key'),
            array_column($imported['sections'], 'section_key'),
            'Section natural keys must be identical across the primary-key shift.',
        );
        $this->assertSame(
            array_column($source['publications'], 'publication_key'),
            array_column($imported['publications'], 'publication_key'),
            'Publication natural keys must be identical across the primary-key shift.',
        );
        $this->assertSame(
            array_column($source['song_videos'], 'section_key'),
            array_column($imported['song_videos'], 'section_key'),
        );

    }

    /**
     * Asset roles are named from portable identities alone, so the *set* of
     * roles must be identical across the round trip even though every path
     * behind it is reallocated.
     *
     * Content grouping is asserted alongside it: B10 lost roles by keying a
     * shared physical file on its path, so the group of four roles sharing one
     * sha256 is exactly the regression this would catch.
     *
     * @param  list<array{role: string, path: string, size: int, sha256: string}>  $source
     * @param  list<array{role: string, path: string, size: int, sha256: string}>  $imported
     */
    private function assertEveryAssetRoleSurvived(array $source, array $imported): void
    {
        $sourceRoles = array_column($source, 'role');
        $importedRoles = array_column($imported, 'role');
        sort($sourceRoles);
        sort($importedRoles);

        $this->assertSame($sourceRoles, $importedRoles, 'The round trip lost or renamed an asset role.');

        $this->assertSame(
            $this->rolesByContent($source),
            $this->rolesByContent($imported),
            'Roles sharing one physical file must still share it after the round trip.',
        );

        $largestGroup = max(array_map('count', $this->rolesByContent($imported)));

        $this->assertSame(
            4,
            $largestGroup,
            'The canary\'s four-roles-one-file case must still be four roles.',
        );
    }

    /**
     * Roles grouped by the content they carry, keyed by nothing path-derived.
     *
     * @param  list<array{role: string, path: string, size: int, sha256: string}>  $roles
     * @return list<list<string>>
     */
    private function rolesByContent(array $roles): array
    {
        $groups = [];

        foreach ($roles as $role) {
            $groups[$role['sha256']][] = $role['role'];
        }

        foreach ($groups as &$group) {
            sort($group);
        }
        unset($group);

        ksort($groups);

        return array_values($groups);
    }

    /**
     * The processing host: media output and private staging are the same disk,
     * which is the isolation the staging guard demands before issuing a context.
     */
    private function useProcessingHostDisks(): void
    {
        config()->set('media-processing.storage.historic_staging_disk', 'local');

        foreach ([
            'media-processing.storage.sermon_disk',
            'media-processing.storage.transcript_disk',
            'media-processing.storage.temp_disk',
            'thumbnail-generation.storage.disk',
            'thumbnail-generation.processing.temp_disk',
        ] as $key) {
            config()->set($key, 'local');
        }
    }

    /**
     * The destination host: staging carries what arrived, production is a
     * separate namespace the transfer copies into. They must be distinct disks.
     */
    private function useDestinationHostDisks(): void
    {
        config()->set('media-processing.storage.historic_staging_disk', 'historic_staging');
        config()->set('media-processing.storage.historic_quarantine_disk', 'local');
        config()->set('media-processing.storage.sermon_disk', 'local');
        config()->set('media-processing.storage.transcript_disk', 'local');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('historic_staging');
        config()->set('media-processing.storage.historic_staging_disk', 'historic_staging');
        config()->set('media-processing.storage.historic_quarantine_disk', 'local');
        config()->set('media-processing.storage.sermon_disk', 'local');
    }
}
