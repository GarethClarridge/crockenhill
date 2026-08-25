<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Data\OpenLpImportResult;
use App\Data\OpenLpParseResult;
use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Services\ChurchService\ImportChurchServiceFromOpenLp;
use App\Services\ChurchService\OpenLpCurationManifest;
use App\Services\Import\HistoricImportProductionGuard;
use App\Services\Song\OpenLpServiceParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;
use Tests\Unit\Services\Import\HistoricImportProductionGuardTest;

class ImportOpenLpDirectoryCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $this->deleteDirectory($directory);
        }

        parent::tearDown();
    }

    #[Test]
    public function dry_run_validates_the_exact_inventory_and_writes_a_canonical_private_report(): void
    {
        [$rawDirectory, $manifestPath] = $this->validCurationFixture();
        $reportPath = dirname($manifestPath).'/report.json';

        $this->artisan('service-tracking:import-openlp-services', [
            '--path' => $rawDirectory,
            '--manifest' => $manifestPath,
            '--dry-run' => true,
            '--report' => $reportPath,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('OpenLP curation preflight passed.')
            ->expectsOutputToContain('Plan hash:');

        $report = json_decode((string) file_get_contents($reportPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('crockenhill-openlp-import-plan', $report['format']);
        $this->assertSame(536, $report['counts']['raw']);
        $this->assertSame(428, $report['counts']['include']);
        $this->assertSame(105, $report['counts']['duplicate-of']);
        $this->assertSame(3, $report['counts']['exclude']);
        $this->assertSame(7, $report['counts']['aliases']);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $report['manifest_hash']);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $report['plan_hash']);
        $this->assertSame(0600, fileperms($reportPath) & 0777);
        $this->assertDatabaseCount('church_services', 0);
    }

    #[Test]
    public function dry_run_parses_every_included_archive_before_emitting_an_applyable_plan(): void
    {
        [$rawDirectory, $manifestPath, $manifest] = $this->validCurationFixture();
        $entry = $manifest['entries'][0];
        $path = "{$rawDirectory}/{$entry['relative_path']}";
        file_put_contents($path, 'not an OpenLP archive');
        $manifest['entries'][0]['sha256'] = hash_file('sha256', $path);
        $manifest['entries'][0]['byte_size'] = filesize($path);
        $duplicatePath = "{$rawDirectory}/{$manifest['entries'][428]['relative_path']}";
        copy($path, $duplicatePath);
        $manifest['entries'][428]['sha256'] = $manifest['entries'][0]['sha256'];
        $manifest['entries'][428]['byte_size'] = $manifest['entries'][0]['byte_size'];
        $manifest['entries'][428]['duplicate_target_hash'] = $manifest['entries'][0]['sha256'];
        $invalidArchiveManifest = $this->writeManifest(dirname($manifestPath), $manifest, 'invalid-archive.json');

        $this->artisan('service-tracking:import-openlp-services', [
            '--path' => $rawDirectory,
            '--manifest' => $invalidArchiveManifest,
            '--dry-run' => true,
        ])
            ->assertFailed()
            ->expectsOutputToContain('must be a valid OpenLP .osz zip archive');

        $this->assertDatabaseCount('church_services', 0);
    }

    /**
     * The refusal comes before the plan-hash check, so an operator who has
     * pointed a production shell at the corpus is told the real problem rather
     * than being sent back for a hash that would not have helped.
     */
    #[Test]
    public function an_unapproved_production_apply_is_refused_before_the_plan_hash_check(): void
    {
        [$rawDirectory, $manifestPath] = $this->validCurationFixture();

        Config::set('church.historic_corpus.production_import_approval', null);
        $this->app['env'] = 'production';

        $this->artisan('service-tracking:import-openlp-services', [
            '--path' => $rawDirectory,
            '--manifest' => $manifestPath,
            '--apply' => true,
            '--plan-hash' => str_repeat('0', 64),
        ])
            ->assertFailed()
            ->expectsOutputToContain('no approved G8 import operation is recorded');

        $this->assertDatabaseCount('church_services', 0);
    }

    /**
     * IC2 §0.1 slice 2: the apply presents this round's exact corpus and plan to the guard.
     *
     * Without the hashes the guard fell back to its pre-IC2 operation/target check, so an approval
     * signed for one OpenLP batch authorised an apply of any other — the lane was guarded against
     * running unapproved, but not against running the wrong corpus under a real approval. The guard
     * itself already refuses a mismatch ({@see HistoricImportProductionGuardTest});
     * what needed proving is that this command hands it something to refuse.
     */
    #[Test]
    public function the_apply_binds_the_guard_to_its_exact_manifest_and_plan(): void
    {
        [$rawDirectory, $manifestPath] = $this->validCurationFixture();
        $plan = app(OpenLpCurationManifest::class)->plan($rawDirectory, $manifestPath);
        $received = [];

        $this->mock(HistoricImportProductionGuard::class)
            ->shouldReceive('refusalFor')
            ->once()
            ->andReturnUsing(function (...$arguments) use (&$received): ?string {
                $received = $arguments;

                return 'refused by the test double';
            });

        $this->artisan('service-tracking:import-openlp-services', [
            '--path' => $rawDirectory,
            '--manifest' => $manifestPath,
            '--apply' => true,
            '--plan-hash' => $plan->planHash,
        ])->assertFailed();

        self::assertSame('service-tracking:import-openlp-services --apply', $received[0]);
        self::assertSame($plan->manifestHash, $received[2]);
        self::assertSame($plan->planHash, $received[3]);
    }

    #[Test]
    public function a_production_dry_run_is_not_blocked(): void
    {
        [$rawDirectory, $manifestPath] = $this->validCurationFixture();

        Config::set('church.historic_corpus.production_import_approval', null);
        $this->app['env'] = 'production';

        $this->artisan('service-tracking:import-openlp-services', [
            '--path' => $rawDirectory,
            '--manifest' => $manifestPath,
            '--dry-run' => true,
            '--report' => dirname($manifestPath).'/production-preflight.json',
        ])->assertSuccessful();
    }

    #[Test]
    public function apply_requires_the_exact_current_dry_run_plan_hash(): void
    {
        [$rawDirectory, $manifestPath] = $this->validCurationFixture();

        $this->artisan('service-tracking:import-openlp-services', [
            '--path' => $rawDirectory,
            '--manifest' => $manifestPath,
            '--apply' => true,
            '--plan-hash' => str_repeat('0', 64),
        ])
            ->assertFailed()
            ->expectsOutputToContain('does not match the current canonical import plan');

        $this->assertDatabaseCount('church_services', 0);
    }

    #[Test]
    public function apply_is_safely_rerunnable_after_a_partial_failure_and_then_becomes_a_full_no_op(): void
    {
        [$rawDirectory, $manifestPath] = $this->validCurationFixture();
        $plan = app(OpenLpCurationManifest::class)->plan($rawDirectory, $manifestPath);
        $failedFilename = '2000-07-19 AM.osz';
        $failedOnce = false;

        $this->mock(ImportChurchServiceFromOpenLp::class)
            ->shouldReceive('import')
            ->times(428)
            ->andReturnUsing(function (UploadedFile $file, ?string $batchHash) use ($failedFilename, &$failedOnce): OpenLpImportResult {
                if ($file->getClientOriginalName() === $failedFilename && ! $failedOnce) {
                    $failedOnce = true;

                    throw new RuntimeException('Deliberate partial failure');
                }

                return $this->recordMockImport($file, $batchHash);
            });

        $this->artisan('service-tracking:import-openlp-services', [
            '--path' => $rawDirectory,
            '--manifest' => $manifestPath,
            '--apply' => true,
            '--plan-hash' => $plan->planHash,
        ])
            ->assertFailed()
            ->expectsOutputToContain($failedFilename);

        $this->assertDatabaseCount('church_service_source_records', 427);

        $this->mock(ImportChurchServiceFromOpenLp::class)
            ->shouldReceive('import')
            ->once()
            ->andReturnUsing(fn (UploadedFile $file, ?string $batchHash): OpenLpImportResult => $this->recordMockImport($file, $batchHash));

        $this->artisan('service-tracking:import-openlp-services', [
            '--path' => $rawDirectory,
            '--manifest' => $manifestPath,
            '--apply' => true,
            '--plan-hash' => $plan->planHash,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('Already present / no-op');

        $this->mock(ImportChurchServiceFromOpenLp::class)
            ->shouldNotReceive('import');

        $this->artisan('service-tracking:import-openlp-services', [
            '--path' => $rawDirectory,
            '--manifest' => $manifestPath,
            '--apply' => true,
            '--plan-hash' => $plan->planHash,
        ])
            ->assertSuccessful();

        $this->assertDatabaseCount('church_service_source_records', 428);
        $this->assertSame(
            [$plan->manifestHash],
            ChurchService::query()
                ->with('sourceRecords')
                ->get()
                ->flatMap->sourceRecords
                ->pluck('batch_hash')
                ->unique()
                ->values()
                ->all(),
        );
    }

    #[Test]
    public function command_requires_an_explicit_mode_and_manifest(): void
    {
        $directory = $this->makeTemporaryDirectory();

        $this->artisan('service-tracking:import-openlp-services', ['--path' => $directory])
            ->assertFailed()
            ->expectsOutputToContain('Choose exactly one mode');

        $this->artisan('service-tracking:import-openlp-services', [
            '--path' => $directory,
            '--dry-run' => true,
        ])
            ->assertFailed()
            ->expectsOutputToContain('private curation manifest is required');
    }

    #[Test]
    public function plan_hash_is_independent_of_manifest_entry_order(): void
    {
        [$rawDirectory, $manifestPath, $manifest] = $this->validCurationFixture();
        $validator = app(OpenLpCurationManifest::class);
        $firstHash = $validator->plan($rawDirectory, $manifestPath)->planHash;

        $manifest['entries'] = array_reverse($manifest['entries']);
        $reorderedManifestPath = $this->writeManifest(dirname($manifestPath), $manifest, 'reordered.json');

        $this->assertSame($firstHash, $validator->plan($rawDirectory, $reorderedManifestPath)->planHash);
    }

    #[Test]
    public function manifest_rejects_unmanifested_extra_and_missing_files(): void
    {
        [$rawDirectory, $manifestPath, $manifest] = $this->validCurationFixture();
        file_put_contents($rawDirectory.'/unexpected.osz', 'unexpected');

        $this->expectManifestFailure($rawDirectory, $manifestPath, 'unmanifested files');
        unlink($rawDirectory.'/unexpected.osz');
        unlink($rawDirectory.'/'.$manifest['entries'][0]['relative_path']);
        $this->expectManifestFailure($rawDirectory, $manifestPath, 'missing files');
    }

    #[Test]
    public function manifest_rejects_hash_mismatches_and_duplicate_included_hashes(): void
    {
        [$rawDirectory, $manifestPath, $manifest] = $this->validCurationFixture();
        $manifest['entries'][0]['sha256'] = str_repeat('f', 64);
        $badHashPath = $this->writeManifest(dirname($manifestPath), $manifest, 'bad-hash.json');
        $this->expectManifestFailure($rawDirectory, $badHashPath, 'SHA-256 mismatch');

        [$rawDirectory, $manifestPath, $manifest] = $this->validCurationFixture();
        $firstPath = $rawDirectory.'/'.$manifest['entries'][0]['relative_path'];
        $secondPath = $rawDirectory.'/'.$manifest['entries'][1]['relative_path'];
        copy($firstPath, $secondPath);
        $manifest['entries'][1]['sha256'] = $manifest['entries'][0]['sha256'];
        $duplicateHashPath = $this->writeManifest(dirname($manifestPath), $manifest, 'duplicate-hash.json');
        $this->expectManifestFailure($rawDirectory, $duplicateHashPath, 'duplicate SHA-256');
    }

    #[Test]
    public function manifest_rejects_a_declared_byte_size_that_does_not_match_the_file(): void
    {
        [$rawDirectory, $manifestPath, $manifest] = $this->validCurationFixture();
        $manifest['entries'][0]['byte_size']++;
        $badSizePath = $this->writeManifest(dirname($manifestPath), $manifest, 'bad-size.json');

        $this->expectManifestFailure($rawDirectory, $badSizePath, 'Byte-size mismatch');
    }

    /**
     * §7.3 requires a stable item key per entry, so curation identity comes from
     * the approved decision rather than being re-derived from the bytes it
     * decided about. `HistoricVideoImporter::manifestItemKey()` falls back to
     * hashing the source file list when the key is absent, which collapses two
     * entries over the same files into one durable job lock.
     */
    #[Test]
    public function manifest_requires_a_stable_unique_item_key_and_source_kind_for_every_entry(): void
    {
        [$rawDirectory, $manifestPath, $manifest] = $this->validCurationFixture();

        $missingKey = $manifest;
        unset($missingKey['entries'][0]['item_key']);
        $this->expectManifestFailure(
            $rawDirectory,
            $this->writeManifest(dirname($manifestPath), $missingKey, 'missing-item-key.json'),
            'requires item_key',
        );

        $duplicateKey = $manifest;
        $duplicateKey['entries'][1]['item_key'] = $duplicateKey['entries'][0]['item_key'];
        $this->expectManifestFailure(
            $rawDirectory,
            $this->writeManifest(dirname($manifestPath), $duplicateKey, 'duplicate-item-key.json'),
            'Duplicate manifest item key',
        );

        $foreignKind = $manifest;
        $foreignKind['entries'][0]['source_kind'] = 'livestream-video';
        $this->expectManifestFailure(
            $rawDirectory,
            $this->writeManifest(dirname($manifestPath), $foreignKind, 'foreign-source-kind.json'),
            'source_kind',
        );

        $missingBatch = $manifest;
        unset($missingBatch['batch_key']);
        $this->expectManifestFailure(
            $rawDirectory,
            $this->writeManifest(dirname($manifestPath), $missingBatch, 'missing-batch-key.json'),
            'batch_key',
        );
    }

    /**
     * §7.3's "decision author/time or approved rule version" is what makes the
     * manifest mutation authority rather than a cache of a filename heuristic.
     */
    #[Test]
    public function manifest_requires_explicit_curation_authority_for_every_entry(): void
    {
        [$rawDirectory, $manifestPath, $manifest] = $this->validCurationFixture();

        $unattributed = $manifest;
        $unattributed['entries'][0]['decided_by'] = null;
        $unattributed['entries'][0]['decided_at'] = null;
        $unattributed['entries'][0]['decision_rule_version'] = null;
        $this->expectManifestFailure(
            $rawDirectory,
            $this->writeManifest(dirname($manifestPath), $unattributed, 'unattributed.json'),
            'curation authority',
        );

        $halfAttributed = $manifest;
        $halfAttributed['entries'][0]['decided_at'] = null;
        $this->expectManifestFailure(
            $rawDirectory,
            $this->writeManifest(dirname($manifestPath), $halfAttributed, 'half-attributed.json'),
            'decided_at',
        );

        $badTimestamp = $manifest;
        $badTimestamp['entries'][0]['decided_at'] = 'last Tuesday';
        $this->expectManifestFailure(
            $rawDirectory,
            $this->writeManifest(dirname($manifestPath), $badTimestamp, 'bad-decided-at.json'),
            'decided_at',
        );

        // An approved rule version is the documented alternative to a named author.
        $ruleAuthorised = $manifest;
        $ruleAuthorised['entries'][0]['decided_by'] = null;
        $ruleAuthorised['entries'][0]['decided_at'] = null;
        $ruleAuthorised['entries'][0]['decision_rule_version'] = 'openlp-filename-identity-v3';
        $rulePath = $this->writeManifest(dirname($manifestPath), $ruleAuthorised, 'rule-authorised.json');

        $this->assertMatchesRegularExpression(
            '/\A[0-9a-f]{64}\z/',
            app(OpenLpCurationManifest::class)->plan($rawDirectory, $rulePath)->planHash,
        );
    }

    /**
     * §7.3 requires an explicit parse/concatenation decision and expected
     * occurrence information for every included item.
     */
    #[Test]
    public function manifest_requires_parse_concatenation_and_expected_count_decisions_for_every_include(): void
    {
        [$rawDirectory, $manifestPath, $manifest] = $this->validCurationFixture();

        $noParse = $manifest;
        $noParse['entries'][0]['parse_decision'] = null;
        $this->expectManifestFailure(
            $rawDirectory,
            $this->writeManifest(dirname($manifestPath), $noParse, 'no-parse-decision.json'),
            'parse_decision',
        );

        $badParse = $manifest;
        $badParse['entries'][0]['parse_decision'] = 'whatever-looks-right';
        $this->expectManifestFailure(
            $rawDirectory,
            $this->writeManifest(dirname($manifestPath), $badParse, 'bad-parse-decision.json'),
            'parse_decision',
        );

        $noConcat = $manifest;
        $noConcat['entries'][0]['concatenation_decision'] = null;
        $this->expectManifestFailure(
            $rawDirectory,
            $this->writeManifest(dirname($manifestPath), $noConcat, 'no-concat-decision.json'),
            'concatenation_decision',
        );

        // A single .osz archive can never be a multi-file concatenation.
        $impossibleConcat = $manifest;
        $impossibleConcat['entries'][0]['concatenation_decision'] = 'lossless';
        $this->expectManifestFailure(
            $rawDirectory,
            $this->writeManifest(dirname($manifestPath), $impossibleConcat, 'impossible-concat.json'),
            'concatenation_decision',
        );

        $noCount = $manifest;
        $noCount['entries'][0]['expected_item_count'] = null;
        $this->expectManifestFailure(
            $rawDirectory,
            $this->writeManifest(dirname($manifestPath), $noCount, 'no-expected-count.json'),
            'expected_item_count',
        );

        // Curation decisions are meaningless for material that is not imported.
        $excludedWithDecisions = $manifest;
        $excludedWithDecisions['entries'][533]['parse_decision'] = 'strict';
        $this->expectManifestFailure(
            $rawDirectory,
            $this->writeManifest(dirname($manifestPath), $excludedWithDecisions, 'excluded-with-decisions.json'),
            'contradictory disposition fields',
        );
    }

    /**
     * Expected occurrence information is only provable if the dry-run parse is
     * reconciled against it; otherwise it is a decorative field.
     */
    #[Test]
    public function dry_run_rejects_an_archive_whose_parsed_item_count_contradicts_the_manifest(): void
    {
        [$rawDirectory, $manifestPath, $manifest] = $this->validCurationFixture();
        $manifest['entries'][0]['expected_item_count'] = 4;
        $wrongCountPath = $this->writeManifest(dirname($manifestPath), $manifest, 'wrong-expected-count.json');

        $this->artisan('service-tracking:import-openlp-services', [
            '--path' => $rawDirectory,
            '--manifest' => $wrongCountPath,
            '--dry-run' => true,
        ])
            ->assertFailed()
            ->expectsOutputToContain('expected item count');

        $this->assertDatabaseCount('church_services', 0);
    }

    /**
     * The alias entries in this corpus exist because historic filenames were
     * corrected, so the archive's embedded .osj name can disagree with the
     * approved logical filename. The parser reports that as `filename_mismatch`.
     * `strict` must fail closed on it; `manifest-authoritative` is how an
     * operator records that they have already adjudicated the identity.
     */
    #[Test]
    public function parse_decision_governs_whether_an_embedded_filename_mismatch_fails_closed(): void
    {
        [$rawDirectory, $manifestPath, $manifest] = $this->validCurationFixture();
        $entry = $manifest['entries'][0];
        $archivePath = "{$rawDirectory}/{$entry['relative_path']}";

        // Rebuild the archive so its embedded .osj claims a different service date.
        $this->writeOpenLpArchive($archivePath, '2001-02-03 PM.osz');
        $hash = hash_file('sha256', $archivePath);
        self::assertIsString($hash);
        $manifest['entries'][0]['sha256'] = $hash;
        $manifest['entries'][0]['byte_size'] = filesize($archivePath);

        // The byte-identical duplicate at 428 tracks entry 0's content.
        $duplicatePath = "{$rawDirectory}/{$manifest['entries'][428]['relative_path']}";
        copy($archivePath, $duplicatePath);
        $manifest['entries'][428]['sha256'] = $hash;
        $manifest['entries'][428]['byte_size'] = $manifest['entries'][0]['byte_size'];
        $manifest['entries'][428]['duplicate_target_hash'] = $hash;

        $strictPath = $this->writeManifest(dirname($manifestPath), $manifest, 'strict-mismatch.json');

        $this->artisan('service-tracking:import-openlp-services', [
            '--path' => $rawDirectory,
            '--manifest' => $strictPath,
            '--dry-run' => true,
        ])
            ->assertFailed()
            ->expectsOutputToContain('embedded .osj identity');

        $manifest['entries'][0]['parse_decision'] = 'manifest-authoritative';
        $authoritativePath = $this->writeManifest(dirname($manifestPath), $manifest, 'authoritative-mismatch.json');

        $this->artisan('service-tracking:import-openlp-services', [
            '--path' => $rawDirectory,
            '--manifest' => $authoritativePath,
            '--dry-run' => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('OpenLP curation preflight passed.');

        $this->assertDatabaseCount('church_services', 0);
    }

    /**
     * A curation field that does not reach the hash cannot bind an apply to the
     * decision that authorised it.
     */
    #[Test]
    public function plan_hash_covers_every_curation_authority_and_expectation_field(): void
    {
        [$rawDirectory, $manifestPath, $manifest] = $this->validCurationFixture();
        $validator = app(OpenLpCurationManifest::class);
        $baseline = $validator->plan($rawDirectory, $manifestPath)->planHash;

        $variants = [
            'item-key' => ['item_key', 'openlp-deliberately-different'],
            'parse-decision' => ['parse_decision', 'manifest-authoritative'],
            'expected-count' => ['expected_item_count', 9],
            'decided-by' => ['decided_by', 'someone.else@crockenhill.test'],
            'decided-at' => ['decided_at', '2026-08-06T11:30:00+00:00'],
        ];

        foreach ($variants as $label => [$field, $value]) {
            $mutated = $manifest;
            $mutated['entries'][0][$field] = $value;
            $mutatedPath = $this->writeManifest(dirname($manifestPath), $mutated, "hash-{$label}.json");

            $this->assertNotSame(
                $baseline,
                $validator->plan($rawDirectory, $mutatedPath)->planHash,
                "Changing {$field} must change the plan hash.",
            );
        }
    }

    /**
     * v1 manifests predate curation authority, so accepting one would let an
     * unattributed corpus through the gate §7.3 exists to close.
     */
    #[Test]
    public function manifest_rejects_the_superseded_version_one_schema(): void
    {
        [$rawDirectory, $manifestPath, $manifest] = $this->validCurationFixture();
        $manifest['version'] = 1;
        $legacyPath = $this->writeManifest(dirname($manifestPath), $manifest, 'legacy-v1.json');

        $this->expectManifestFailure($rawDirectory, $legacyPath, 'Unsupported OpenLP curation manifest format or version');
    }

    #[Test]
    public function manifest_rejects_path_traversal_and_absolute_paths(): void
    {
        [$rawDirectory, $manifestPath, $manifest] = $this->validCurationFixture();
        $manifest['entries'][0]['relative_path'] = '../outside.osz';
        $traversalPath = $this->writeManifest(dirname($manifestPath), $manifest, 'traversal.json');
        $this->expectManifestFailure($rawDirectory, $traversalPath, 'Unsafe manifest path');

        $manifest['entries'][0]['relative_path'] = '/tmp/outside.osz';
        $absolutePath = $this->writeManifest(dirname($manifestPath), $manifest, 'absolute.json');
        $this->expectManifestFailure($rawDirectory, $absolutePath, 'Unsafe manifest path');
    }

    #[Test]
    public function approved_openlp_paths_are_rechecked_for_symlinks_before_apply(): void
    {
        [$rawDirectory, $manifestPath, $manifest] = $this->validCurationFixture();
        $plan = app(OpenLpCurationManifest::class)->plan($rawDirectory, $manifestPath);
        $sourcePath = "{$rawDirectory}/{$manifest['entries'][0]['relative_path']}";
        $targetPath = "{$rawDirectory}/{$manifest['entries'][1]['relative_path']}";

        unlink($sourcePath);
        symlink($targetPath, $sourcePath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing or symlinked');

        app(OpenLpCurationManifest::class)->verifyIncludes($rawDirectory, $plan);
    }

    /**
     * The window between an operator approving a plan and the batch applying it is
     * wide enough for the corpus to be re-synced. Approved bytes are therefore
     * re-hashed at apply time, not merely at preflight.
     */
    #[Test]
    public function an_archive_replaced_after_the_dry_run_is_rejected_before_apply(): void
    {
        [$rawDirectory, $manifestPath] = $this->validCurationFixture();
        $curationManifest = app(OpenLpCurationManifest::class);
        $plan = $curationManifest->plan($rawDirectory, $manifestPath);
        $curationManifest->validateIncludesForDryRun($rawDirectory, $plan);

        $approved = $plan->includes[0];
        $replacedPath = "{$rawDirectory}/{$approved['relative_path']}";
        $this->writeOpenLpArchive($replacedPath, $approved['logical_upload_filename']);
        file_put_contents($replacedPath, str_repeat('padding', 64), FILE_APPEND);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Byte-size mismatch for {$approved['relative_path']}");

        $curationManifest->verifyInclude($rawDirectory, $approved);
    }

    #[Test]
    public function manifest_rejects_every_accounting_count_drift(): void
    {
        foreach (['include', 'duplicate-of', 'exclude', 'aliases'] as $count) {
            [$rawDirectory, $manifestPath, $manifest] = $this->validCurationFixture();

            if ($count === 'include') {
                $manifest['entries'][427]['disposition'] = 'exclude';
                $manifest['entries'][427]['logical_upload_filename'] = null;
                $manifest['entries'][427]['resolved_date'] = null;
                $manifest['entries'][427]['resolved_service'] = null;
                $manifest['entries'][427]['alias_reason'] = null;
                $manifest['entries'][427]['exclusion_reason'] = 'Count mutation';
                $manifest['entries'][427]['parse_decision'] = null;
                $manifest['entries'][427]['concatenation_decision'] = null;
                $manifest['entries'][427]['expected_item_count'] = null;
            }

            if ($count === 'duplicate-of') {
                $duplicateOffset = 428;
                $manifest['entries'][$duplicateOffset]['disposition'] = 'exclude';
                $manifest['entries'][$duplicateOffset]['duplicate_target_hash'] = null;
                $manifest['entries'][$duplicateOffset]['exclusion_reason'] = 'Count mutation';
            }

            if ($count === 'exclude') {
                $manifest['entries'][533]['disposition'] = 'duplicate-of';
                $manifest['entries'][533]['sha256'] = $manifest['entries'][0]['sha256'];
                $manifest['entries'][533]['byte_size'] = $manifest['entries'][0]['byte_size'];
                $manifest['entries'][533]['duplicate_target_hash'] = $manifest['entries'][0]['sha256'];
                $manifest['entries'][533]['exclusion_reason'] = null;
                copy(
                    $rawDirectory.'/'.$manifest['entries'][0]['relative_path'],
                    $rawDirectory.'/'.$manifest['entries'][533]['relative_path'],
                );
            }

            if ($count === 'aliases') {
                $manifest['entries'][7]['logical_upload_filename'] = $manifest['entries'][7]['resolved_date'].' AM corrected.osz';
                $manifest['entries'][7]['alias_reason'] = 'Count mutation';
            }

            $mutatedPath = $this->writeManifest(dirname($manifestPath), $manifest, "count-{$count}.json");
            $this->expectManifestFailure($rawDirectory, $mutatedPath, 'accounting mismatch');
        }
    }

    /**
     * F4. §13.1 instructs a remeasurement of the OpenLP accounting against the mounted
     * drive and says plainly that the tracked 536/428/105/3/7 is a reconciliation
     * target rather than proof. While those numbers lived in a class constant, any
     * other answer was unrepresentable — so the remeasurement §13.1 asks for could only
     * have been recorded by editing code. A differently-sized corpus must validate on
     * the strength of its own approved declaration.
     */
    #[Test]
    public function manifest_accounting_is_declared_by_the_manifest_rather_than_frozen_in_code(): void
    {
        [$rawDirectory, $manifestPath] = $this->validCurationFixture(
            includeCount: 12,
            duplicateCount: 4,
            excludeCount: 2,
            aliasCount: 3,
        );

        $plan = app(OpenLpCurationManifest::class)->plan($rawDirectory, $manifestPath);

        $this->assertSame([
            'raw' => 18,
            'include' => 12,
            'duplicate-of' => 4,
            'exclude' => 2,
            'aliases' => 3,
        ], $plan->counts);
        $this->assertCount(12, $plan->includes);
    }

    /**
     * The declaration is what the constant was for: a manifest that has quietly lost
     * entries between approval and apply must fail rather than succeed over a smaller
     * corpus. Moving the numbers into the manifest keeps that property and drops only
     * the assumption that there is exactly one right answer.
     */
    #[Test]
    public function manifest_rejects_a_declaration_that_contradicts_its_own_entries(): void
    {
        [$rawDirectory, $manifestPath, $manifest] = $this->validCurationFixture(
            includeCount: 12,
            duplicateCount: 4,
            excludeCount: 2,
            aliasCount: 3,
        );
        $manifest['expected_counts']['include'] = 11;
        $mutatedPath = $this->writeManifest(dirname($manifestPath), $manifest, 'declared-mismatch.json');

        $this->expectManifestFailure($rawDirectory, $mutatedPath, 'accounting mismatch');
    }

    /**
     * An absent declaration is not defaulted, on the same principle the class already
     * applies to a v1 manifest: it carries no attribution, so it is rejected rather
     * than assumed.
     */
    #[Test]
    public function manifest_rejects_an_undeclared_accounting(): void
    {
        [$rawDirectory, $manifestPath, $manifest] = $this->validCurationFixture(
            includeCount: 12,
            duplicateCount: 4,
            excludeCount: 2,
            aliasCount: 3,
        );
        unset($manifest['expected_counts']);
        $undeclaredPath = $this->writeManifest(dirname($manifestPath), $manifest, 'undeclared-counts.json');

        $this->expectManifestFailure($rawDirectory, $undeclaredPath, 'declare its expected accounting');
    }

    #[Test]
    public function manifest_rejects_contradictory_and_duplicate_aliases(): void
    {
        [$rawDirectory, $manifestPath, $manifest] = $this->validCurationFixture();
        $manifest['entries'][7]['alias_reason'] = 'Claims correction without changing the filename';
        $contradictoryPath = $this->writeManifest(dirname($manifestPath), $manifest, 'contradictory-alias.json');
        $this->expectManifestFailure($rawDirectory, $contradictoryPath, 'contradictory alias');

        $manifest['entries'][7]['alias_reason'] = null;
        $manifest['entries'][7]['logical_upload_filename'] = $manifest['entries'][0]['logical_upload_filename'];
        $manifest['entries'][7]['resolved_date'] = $manifest['entries'][0]['resolved_date'];
        $manifest['entries'][7]['alias_reason'] = 'Duplicates another corrected alias';
        $duplicateAliasPath = $this->writeManifest(dirname($manifestPath), $manifest, 'duplicate-alias.json');
        $this->expectManifestFailure($rawDirectory, $duplicateAliasPath, 'duplicate logical upload filenames');
    }

    #[Test]
    public function manifest_rejects_duplicate_service_identity_and_filename_identity_contradictions(): void
    {
        [$rawDirectory, $manifestPath, $manifest] = $this->validCurationFixture();
        $manifest['entries'][8]['resolved_date'] = $manifest['entries'][7]['resolved_date'];
        $identityPath = $this->writeManifest(dirname($manifestPath), $manifest, 'identity-conflict.json');
        $this->expectManifestFailure($rawDirectory, $identityPath, 'contradict its logical upload filename');

        $manifest['entries'][8]['logical_upload_filename'] = $manifest['entries'][7]['resolved_date'].' AM copy.osz';
        $manifest['entries'][8]['alias_reason'] = 'Contrived duplicate identity';
        $manifest['entries'][8]['resolved_date'] = $manifest['entries'][7]['resolved_date'];
        $manifest['entries'][8]['resolved_service'] = $manifest['entries'][7]['resolved_service'];
        $duplicateIdentityPath = $this->writeManifest(dirname($manifestPath), $manifest, 'duplicate-identity.json');
        $this->expectManifestFailure($rawDirectory, $duplicateIdentityPath, 'duplicate logical service identities');
    }

    /**
     * @return array{string, string, array<string, mixed>}
     */
    private function validCurationFixture(
        int $includeCount = 428,
        int $duplicateCount = 105,
        int $excludeCount = 3,
        int $aliasCount = 7,
    ): array {
        $root = $this->makeTemporaryDirectory();
        $rawDirectory = "{$root}/raw";
        mkdir($rawDirectory);
        $entries = [];
        $includedHashes = [];
        $origin = new \DateTimeImmutable('2000-01-01');

        for ($index = 0; $index < $includeCount; $index++) {
            $date = $origin->modify("+{$index} days")->format('Y-m-d');
            $relativePath = $index < $aliasCount
                ? "uncorrected-{$index}.osz"
                : "{$date} AM.osz";
            $logicalFilename = "{$date} AM.osz";
            $this->writeOpenLpArchive("{$rawDirectory}/{$relativePath}", $logicalFilename);
            $hash = hash_file('sha256', "{$rawDirectory}/{$relativePath}");
            self::assertIsString($hash);
            $includedHashes[] = $hash;
            $entries[] = $this->entry(
                relativePath: $relativePath,
                hash: $hash,
                disposition: 'include',
                logicalFilename: $logicalFilename,
                resolvedDate: $date,
                resolvedService: 'morning',
                aliasReason: $index < $aliasCount ? 'Corrected historic filename' : null,
            );
        }

        for ($index = 0; $index < $duplicateCount; $index++) {
            $relativePath = "duplicates/duplicate-{$index}.osz";
            $targetPath = dirname("{$rawDirectory}/{$relativePath}");

            if (! is_dir($targetPath)) {
                mkdir($targetPath);
            }

            copy("{$rawDirectory}/{$entries[$index]['relative_path']}", "{$rawDirectory}/{$relativePath}");
            $entries[] = $this->entry(
                relativePath: $relativePath,
                hash: $includedHashes[$index],
                disposition: 'duplicate-of',
                duplicateTargetHash: $includedHashes[$index],
            );
        }

        for ($index = 0; $index < $excludeCount; $index++) {
            $relativePath = "excluded-{$index}.osz";
            $contents = "excluded archive {$index}";
            file_put_contents("{$rawDirectory}/{$relativePath}", $contents);
            $entries[] = $this->entry(
                relativePath: $relativePath,
                hash: hash('sha256', $contents),
                disposition: 'exclude',
                exclusionReason: 'Not a church service',
            );
        }

        foreach ($entries as &$entry) {
            $size = filesize("{$rawDirectory}/{$entry['relative_path']}");
            self::assertIsInt($size);
            $entry['byte_size'] = $size;
        }
        unset($entry);

        $manifest = [
            'format' => 'crockenhill-openlp-curation',
            'version' => 3,
            'batch_key' => 'openlp-archive-2026-08',
            'expected_counts' => [
                'raw' => $includeCount + $duplicateCount + $excludeCount,
                'include' => $includeCount,
                'duplicate-of' => $duplicateCount,
                'exclude' => $excludeCount,
                'aliases' => $aliasCount,
            ],
            'entries' => $entries,
        ];
        $manifestPath = $this->writeManifest($root, $manifest);

        return [$rawDirectory, $manifestPath, $manifest];
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(
        string $relativePath,
        string $hash,
        string $disposition,
        ?string $logicalFilename = null,
        ?string $resolvedDate = null,
        ?string $resolvedService = null,
        ?string $aliasReason = null,
        ?string $duplicateTargetHash = null,
        ?string $exclusionReason = null,
        ?string $itemKey = null,
        ?string $parseDecision = null,
        ?string $concatenationDecision = null,
        ?int $expectedItemCount = null,
    ): array {
        $isInclude = $disposition === 'include';

        return [
            'item_key' => $itemKey ?? 'openlp-'.substr(hash('sha256', $relativePath), 0, 16),
            'source_kind' => 'openlp',
            'relative_path' => $relativePath,
            'sha256' => $hash,
            'byte_size' => null,
            'disposition' => $disposition,
            'duplicate_target_hash' => $duplicateTargetHash,
            'logical_upload_filename' => $logicalFilename,
            'resolved_date' => $resolvedDate,
            'resolved_service' => $resolvedService,
            'alias_reason' => $aliasReason,
            'exclusion_reason' => $exclusionReason,
            'parse_decision' => $isInclude ? ($parseDecision ?? 'strict') : $parseDecision,
            'concatenation_decision' => $isInclude ? ($concatenationDecision ?? 'none') : $concatenationDecision,
            'expected_item_count' => $isInclude ? ($expectedItemCount ?? 0) : $expectedItemCount,
            'decided_by' => 'curator@crockenhill.test',
            'decided_at' => '2026-08-06T09:00:00+00:00',
            'decision_rule_version' => null,
        ];
    }

    private function writeOpenLpArchive(string $path, string $logicalFilename): void
    {
        $archive = new \ZipArchive;
        self::assertTrue($archive->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
        self::assertTrue($archive->addFromString(
            str_replace('.osz', '.osj', $logicalFilename),
            json_encode([], JSON_THROW_ON_ERROR),
        ));
        self::assertTrue($archive->close());
    }

    /** @param array<string, mixed> $manifest */
    private function writeManifest(string $directory, array $manifest, string $filename = 'manifest.json'): string
    {
        $path = "{$directory}/{$filename}";
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $path;
    }

    private function expectManifestFailure(string $rawDirectory, string $manifestPath, string $message): void
    {
        try {
            app(OpenLpCurationManifest::class)->plan($rawDirectory, $manifestPath);
            self::fail("Expected manifest failure containing: {$message}");
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function recordMockImport(UploadedFile $file, ?string $batchHash): OpenLpImportResult
    {
        $identity = app(OpenLpServiceParser::class)
            ->identityFromFilename($file->getClientOriginalName());

        if ($identity === null || $batchHash === null) {
            throw new RuntimeException('Mock import received an invalid manifest identity.');
        }

        $service = ChurchService::factory()->create([
            'date' => $identity['date'],
            'service' => $identity['service']->value,
            'original_filename' => $file->getClientOriginalName(),
        ]);
        $service->sourceRecords()->create([
            'source' => ChurchServiceSource::OpenLp,
            'source_key' => $file->getClientOriginalName(),
            'revision_hash' => hash('sha256', "revision:{$file->getClientOriginalName()}"),
            'input_hash' => hash_file('sha256', $file->getRealPath()),
            'batch_hash' => $batchHash,
            'processing_fingerprint' => ['format' => 'test'],
            'payload_complete' => true,
            'captured_at' => now(),
        ]);
        $parseResult = new OpenLpParseResult(
            date: $identity['date'],
            service: $identity['service'],
            items: [],
            needsReview: false,
            importMetadata: [],
        );

        return new OpenLpImportResult(
            churchService: $service,
            parseResult: $parseResult,
            wasCreated: true,
            syncResult: [],
            linkResult: [
                'dry_run' => false,
                'processed' => 0,
                'matched' => 0,
                'unmatched' => 0,
                'updated' => 0,
                'unchanged' => 0,
                'cleared' => 0,
                'match_types' => [],
            ],
        );
    }

    private function makeTemporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/openlp-curation-'.bin2hex(random_bytes(8));

        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            self::fail('Failed to create temporary directory.');
        }

        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($directory);
    }
}
