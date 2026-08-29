<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class InventoryHistoricVideoPilotCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('scratch/tests/historic-pilot-ledger-'.bin2hex(random_bytes(6)));
        mkdir($this->root, 0755, true);
        Config::set('filesystems.disks.pilot_staging', [
            'driver' => 'local',
            'root' => $this->root.'/staging',
            'throw' => true,
        ]);
        Storage::forgetDisk('pilot_staging');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }

            rmdir($this->root);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_captures_an_owned_and_hashed_pilot_ledger(): void
    {
        $manifestHash = str_repeat('a', 64);
        $operation = $this->createHistoricImportOperation(attributes: [
            'manifest_hashes' => ['historic_video' => $manifestHash],
            'max_cost_minor_units' => 1_000,
        ]);
        $processingId = '11111111-1111-4111-8111-111111111111';
        $batchRoot = 'historic-batches/'.str_repeat('b', 64);
        MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => $processingId,
            'historic_import_operation_id' => $operation->id,
            'status' => ProcessingStatus::Completed,
            'processing_metadata' => $this->metadata('pilot-one', $manifestHash, $batchRoot),
        ]);
        Storage::disk('pilot_staging')->put("{$batchRoot}/service-audio/{$processingId}.mp3", 'audio');
        $selection = $this->selection(['pilot-one'], $manifestHash);
        $output = $this->root.'/ledger.json';

        $this->artisan('historic:inventory-video-pilot', [
            'selection' => $selection,
            '--operation' => $operation->operation_id,
            '--output' => $output,
        ])
            ->expectsOutput('Exit gate: PASS')
            ->assertSuccessful();

        $ledger = json_decode((string) file_get_contents($output), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($ledger['exit_gate_passed']);
        $this->assertSame(1, $ledger['reconciliation']['distinct_observed_items']);
        $this->assertSame('completed', $ledger['identities'][0]['disposition']);
        $this->assertNull($ledger['identities'][0]['runs'][0]['inventory_error']);
        $this->assertSame('manifest_item:pilot-one', $ledger['staging_files'][0]['owner']);
        $this->assertSame(hash('sha256', 'audio'), $ledger['staging_files'][0]['sha256']);
        $this->assertSame(0600, fileperms($output) & 0777);
    }

    #[Test]
    public function it_fails_when_a_selected_identity_disappeared_without_evidence(): void
    {
        $manifestHash = str_repeat('a', 64);
        $operation = $this->createHistoricImportOperation(attributes: [
            'manifest_hashes' => ['historic_video' => $manifestHash],
        ]);
        $selection = $this->selection(['missing-pilot'], $manifestHash);
        $output = $this->root.'/failed-ledger.json';

        $this->artisan('historic:inventory-video-pilot', [
            'selection' => $selection,
            '--operation' => $operation->operation_id,
            '--output' => $output,
        ])
            ->expectsOutput('Exit gate: FAIL')
            ->assertFailed();

        $ledger = json_decode((string) file_get_contents($output), true, flags: JSON_THROW_ON_ERROR);

        $this->assertFalse($ledger['exit_gate_passed']);
        $this->assertSame('unexplained_absence', $ledger['identities'][0]['disposition']);
        $this->assertContains(
            'Selected identity missing-pilot has no named disposition: unexplained_absence.',
            $ledger['errors'],
        );
        $this->assertContains('Pilot runs do not carry one exact staging context.', $ledger['errors']);
    }

    /**
     * `2020-03-22-morning` ran twice: the RMS stage failed, and a later dispatch
     * carried the identity to a sermon. Two runs for one key is the shape of a
     * retry, not of a gap, so the freeze names it and passes.
     */
    #[Test]
    public function it_names_a_failed_attempt_followed_by_a_completed_run(): void
    {
        $manifestHash = str_repeat('a', 64);
        $operation = $this->createHistoricImportOperation(attributes: [
            'manifest_hashes' => ['historic_video' => $manifestHash],
        ]);
        $batchRoot = 'historic-batches/'.str_repeat('b', 64);
        MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => '22222222-2222-4222-8222-222222222222',
            'historic_import_operation_id' => $operation->id,
            'status' => ProcessingStatus::Failed,
            'processing_metadata' => $this->metadata('retried-pilot', $manifestHash, $batchRoot),
        ]);
        MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => '33333333-3333-4333-8333-333333333333',
            'historic_import_operation_id' => $operation->id,
            'status' => ProcessingStatus::Completed,
            'processing_metadata' => $this->metadata('retried-pilot', $manifestHash, $batchRoot),
        ]);
        Storage::disk('pilot_staging')->put(
            "{$batchRoot}/service-audio/33333333-3333-4333-8333-333333333333.mp3",
            'audio',
        );
        $output = $this->root.'/retry-ledger.json';

        $this->artisan('historic:inventory-video-pilot', [
            'selection' => $this->selection(['retried-pilot'], $manifestHash),
            '--operation' => $operation->operation_id,
            '--output' => $output,
        ])
            ->expectsOutput('Exit gate: PASS')
            ->assertSuccessful();

        $ledger = json_decode((string) file_get_contents($output), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('completed_after_failed_attempts', $ledger['identities'][0]['disposition']);
        $this->assertSame(['retried-pilot'], $ledger['reconciliation']['duplicate_item_keys']);
    }

    /**
     * `2023-09-03-morning` produced no run because a sermon already stood on that
     * date, which is the importer's `skip-exists` guard doing its job. The
     * manifest is the only authority for the date and service that proves it.
     */
    #[Test]
    public function it_names_an_identity_the_dispatch_skipped_for_an_existing_sermon(): void
    {
        $manifestHash = str_repeat('a', 64);
        $operation = $this->createHistoricImportOperation(attributes: [
            'manifest_hashes' => ['historic_video' => $manifestHash],
        ]);
        $batchRoot = 'historic-batches/'.str_repeat('b', 64);
        MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => '44444444-4444-4444-8444-444444444444',
            'historic_import_operation_id' => $operation->id,
            'status' => ProcessingStatus::Completed,
            'processing_metadata' => $this->metadata('dispatched-pilot', $manifestHash, $batchRoot),
        ]);
        Storage::disk('pilot_staging')->put(
            "{$batchRoot}/service-audio/44444444-4444-4444-8444-444444444444.mp3",
            'audio',
        );
        $sermon = Sermon::factory()->create([
            'date' => '2023-09-03',
            'service' => 'morning',
            'livestream_processing_id' => null,
        ]);
        $output = $this->root.'/skipped-ledger.json';

        $this->artisan('historic:inventory-video-pilot', [
            'selection' => $this->selection(['dispatched-pilot', 'skipped-pilot'], $manifestHash),
            '--operation' => $operation->operation_id,
            '--manifest' => $this->manifest($operation->batch_key, [
                'dispatched-pilot' => ['2026-02-08', 'morning'],
                'skipped-pilot' => ['2023-09-03', 'morning'],
            ]),
            '--output' => $output,
        ])
            ->expectsOutput('Exit gate: PASS')
            ->assertSuccessful();

        $ledger = json_decode((string) file_get_contents($output), true, flags: JSON_THROW_ON_ERROR);
        $skipped = $ledger['identities'][1];

        $this->assertSame('skipped-pilot', $skipped['item_key']);
        $this->assertSame('skipped_pre_existing_sermon', $skipped['disposition']);
        $this->assertSame('pre_existing_sermon', $skipped['absence_evidence']['reason']);
        $this->assertSame($sermon->id, $skipped['absence_evidence']['sermons'][0]['sermon_id']);
        $this->assertSame(
            ['completed' => 1, 'skipped_pre_existing_sermon' => 1],
            $ledger['reconciliation']['dispositions'],
        );
    }

    /**
     * macOS writes an AppleDouble sidecar beside each staged file on the exFAT
     * drive. The first capture inventoried nothing at all because Flysystem's
     * deep listing aborts on the first entry it cannot stat.
     */
    #[Test]
    public function it_names_apple_double_sidecars_instead_of_abandoning_the_census(): void
    {
        $manifestHash = str_repeat('a', 64);
        $operation = $this->createHistoricImportOperation(attributes: [
            'manifest_hashes' => ['historic_video' => $manifestHash],
        ]);
        $processingId = '55555555-5555-4555-8555-555555555555';
        $batchRoot = 'historic-batches/'.str_repeat('b', 64);
        MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => $processingId,
            'historic_import_operation_id' => $operation->id,
            'status' => ProcessingStatus::Completed,
            'processing_metadata' => $this->metadata('sidecar-pilot', $manifestHash, $batchRoot),
        ]);
        $disk = Storage::disk('pilot_staging');
        $disk->put("{$batchRoot}/temp/rms_{$processingId}.log", 'rms');
        $disk->put("{$batchRoot}/temp/._rms_{$processingId}.log", 'xattr');
        $output = $this->root.'/sidecar-ledger.json';

        $this->artisan('historic:inventory-video-pilot', [
            'selection' => $this->selection(['sidecar-pilot'], $manifestHash),
            '--operation' => $operation->operation_id,
            '--output' => $output,
        ])
            ->expectsOutput('Exit gate: PASS')
            ->assertSuccessful();

        $ledger = json_decode((string) file_get_contents($output), true, flags: JSON_THROW_ON_ERROR);
        $sidecar = collect($ledger['staging_files'])
            ->firstWhere('path', "temp/._rms_{$processingId}.log");

        $this->assertSame('platform_sidecar', $sidecar['disposition']);
        $this->assertSame('manifest_item:sidecar-pilot', $sidecar['owner']);
        $this->assertSame(2, $ledger['byte_census']['file_count']);
        $this->assertSame(
            ['platform_sidecar' => 1, 'temporary_or_retryable_input' => 1],
            $ledger['byte_census']['by_disposition'],
        );
    }

    /**
     * Fifteen RMS logs, two extraction working copies and a thumbnail frame
     * survived the pilot under job UUIDs no run records. They are batch-owned
     * leaks, not evidence nobody can account for, so the freeze names the shape.
     */
    #[Test]
    public function it_names_orphaned_working_copies_and_refuses_anything_else(): void
    {
        $manifestHash = str_repeat('a', 64);
        $operation = $this->createHistoricImportOperation(attributes: [
            'manifest_hashes' => ['historic_video' => $manifestHash],
        ]);
        $batchRoot = 'historic-batches/'.str_repeat('b', 64);
        MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => '66666666-6666-4666-8666-666666666666',
            'historic_import_operation_id' => $operation->id,
            'status' => ProcessingStatus::Completed,
            'processing_metadata' => $this->metadata('residue-pilot', $manifestHash, $batchRoot),
        ]);
        $disk = Storage::disk('pilot_staging');
        $disk->put("{$batchRoot}/temp/rms_19c3b03d-a664-490c-aeef-2669e7950d34.log", 'rms');
        $disk->put("{$batchRoot}/livestream/temp/8cfacc7b-d1b8-4506-8ea2-141cf3ecfcc6.mp4", 'partial');
        $disk->put("{$batchRoot}/temp/thumbnails/frame_9ed35f6e-35f8-4e02-94fb-38a324bc8920.webp", 'frame');
        $output = $this->root.'/residue-ledger.json';

        $this->artisan('historic:inventory-video-pilot', [
            'selection' => $this->selection(['residue-pilot'], $manifestHash),
            '--operation' => $operation->operation_id,
            '--output' => $output,
        ])
            ->expectsOutput('Exit gate: PASS')
            ->assertSuccessful();

        $ledger = json_decode((string) file_get_contents($output), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([
            'orphaned_extraction_working_copy' => 1,
            'orphaned_rms_working_copy' => 1,
            'orphaned_thumbnail_frame' => 1,
        ], $ledger['byte_census']['by_disposition']);

        $disk->put("{$batchRoot}/temp/who-put-this-here.bin", 'unknown');
        $stubborn = $this->root.'/stubborn-ledger.json';

        $this->artisan('historic:inventory-video-pilot', [
            'selection' => $this->selection(['residue-pilot'], $manifestHash),
            '--operation' => $operation->operation_id,
            '--output' => $stubborn,
        ])
            ->expectsOutput('Exit gate: FAIL')
            ->assertFailed();

        $stubbornLedger = json_decode((string) file_get_contents($stubborn), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            1,
            $stubbornLedger['byte_census']['by_disposition']['unexplained_residue'],
        );
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $identities
     */
    private function manifest(string $batchKey, array $identities): string
    {
        $path = $this->root.'/manifest-'.bin2hex(random_bytes(4)).'.json';
        $entries = [];

        foreach ($identities as $itemKey => [$date, $service]) {
            $entries[] = ['item_key' => $itemKey, 'date' => $date, 'service' => $service];
        }

        file_put_contents($path, json_encode([
            'format' => 'crockenhill-historic-video-curation-manifest',
            'version' => 1,
            'batch_key' => $batchKey,
            'entries' => $entries,
        ], JSON_THROW_ON_ERROR));

        return $path;
    }

    /** @return array<string, mixed> */
    private function metadata(string $itemKey, string $manifestHash, string $batchRoot): array
    {
        return [
            'historic_import' => [
                'manifest_item_key' => $itemKey,
                'staging_context' => [
                    'manifest_hash' => $manifestHash,
                    'plan_hash' => str_repeat('b', 64),
                    'staging_disk' => 'pilot_staging',
                    'batch_root' => $batchRoot,
                    'storage_identity' => [
                        'driver' => 'local',
                        'bucket' => null,
                        'root_fingerprint' => str_repeat('c', 64),
                        'prefix_fingerprint' => str_repeat('d', 64),
                    ],
                ],
            ],
        ];
    }

    /** @param list<string> $itemKeys */
    private function selection(array $itemKeys, string $manifestHash): string
    {
        $path = $this->root.'/selection-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($path, json_encode([
            'format' => 'crockenhill-historic-video-pilot-selection',
            'version' => 1,
            'derived_from' => ['manifest_hash' => $manifestHash],
            'item_keys' => $itemKeys,
        ], JSON_THROW_ON_ERROR));

        return $path;
    }
}
