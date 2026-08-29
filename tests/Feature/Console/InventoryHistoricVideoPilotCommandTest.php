<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
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
        $this->assertNull($ledger['identities'][0]['runs'][0]['inventory_error']);
        $this->assertSame('manifest_item:pilot-one', $ledger['staging_files'][0]['owner']);
        $this->assertSame(hash('sha256', 'audio'), $ledger['staging_files'][0]['sha256']);
        $this->assertSame(0600, fileperms($output) & 0777);
    }

    #[Test]
    public function it_writes_a_failed_ledger_when_a_selected_identity_has_no_run(): void
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
        $this->assertSame(['missing-pilot'], $ledger['reconciliation']['missing_item_keys']);
        $this->assertContains('Pilot runs do not carry one exact staging context.', $ledger['errors']);
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
