<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Data\OpenLpImportResult;
use App\Data\OpenLpParseResult;
use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Services\ChurchService\ImportChurchServiceFromOpenLp;
use App\Services\ChurchService\OpenLpCurationManifest;
use App\Services\Song\OpenLpServiceParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

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
    private function validCurationFixture(): array
    {
        $root = $this->makeTemporaryDirectory();
        $rawDirectory = "{$root}/raw";
        mkdir($rawDirectory);
        $entries = [];
        $includedHashes = [];
        $origin = new \DateTimeImmutable('2000-01-01');

        for ($index = 0; $index < 428; $index++) {
            $date = $origin->modify("+{$index} days")->format('Y-m-d');
            $relativePath = $index < 7
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
                aliasReason: $index < 7 ? 'Corrected historic filename' : null,
            );
        }

        for ($index = 0; $index < 105; $index++) {
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

        for ($index = 0; $index < 3; $index++) {
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
            'version' => 1,
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
    ): array {
        return [
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
