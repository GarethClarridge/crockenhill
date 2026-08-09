<?php

declare(strict_types=1);

namespace Tests\Integration\Services\Import;

use App\Models\HistoricImportAssetTransfer;
use App\Services\Import\HistoricImportAssetTransferService;
use App\Services\Import\HistoricImportJournal;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class HistoricImportAssetTransferServiceTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('historic_transfer_source');
        Storage::fake('historic_transfer_destination');
    }

    #[Test]
    public function it_streams_verifies_inventories_and_idempotently_resumes_a_completed_transfer(): void
    {
        $operation = $this->createHistoricImportOperation();
        $contents = str_repeat('historic-media', 1_024);
        Storage::disk('historic_transfer_source')->put('bundle/video.mp4', $contents);
        $service = app(HistoricImportAssetTransferService::class);

        $first = $service->transfer(
            $operation,
            'historic_transfer_source',
            'bundle/video.mp4',
            'historic_transfer_destination',
            'received/video.mp4',
            strlen($contents),
            hash('sha256', $contents),
        );
        $second = $service->transfer(
            $operation,
            'historic_transfer_source',
            'bundle/video.mp4',
            'historic_transfer_destination',
            'received/video.mp4',
            strlen($contents),
            hash('sha256', $contents),
        );

        $this->assertTrue($first->is($second));
        $this->assertSame('verified', $second->state);
        $this->assertDatabaseCount('historic_import_asset_transfers', 1);
        Storage::disk('historic_transfer_destination')->assertExists('received/video.mp4');
        app(HistoricImportJournal::class)->verify($operation);
    }

    #[Test]
    public function it_recovers_when_copy_finished_before_the_database_acknowledgement(): void
    {
        $operation = $this->createHistoricImportOperation();
        $contents = 'already copied bytes';
        $sha256 = hash('sha256', $contents);
        Storage::disk('historic_transfer_source')->put('bundle/audio.mp3', $contents);
        Storage::disk('historic_transfer_destination')->put('received/audio.mp3', $contents);
        $transferKey = CanonicalJson::hash([
            'operation_id' => $operation->operation_id,
            'source_disk' => 'historic_transfer_source',
            'source_path' => 'bundle/audio.mp3',
            'destination_disk' => 'historic_transfer_destination',
            'destination_path' => 'received/audio.mp3',
            'byte_size' => strlen($contents),
            'sha256' => $sha256,
        ]);
        HistoricImportAssetTransfer::query()->create([
            'historic_import_operation_id' => $operation->id,
            'transfer_key' => $transferKey,
            'source_disk' => 'historic_transfer_source',
            'source_path' => 'bundle/audio.mp3',
            'destination_disk' => 'historic_transfer_destination',
            'destination_path' => 'received/audio.mp3',
            'byte_size' => strlen($contents),
            'sha256' => $sha256,
            'state' => 'started',
            'attempts' => 1,
            'started_at' => now(),
        ]);

        $transfer = app(HistoricImportAssetTransferService::class)->transfer(
            $operation,
            'historic_transfer_source',
            'bundle/audio.mp3',
            'historic_transfer_destination',
            'received/audio.mp3',
            strlen($contents),
            $sha256,
        );

        $this->assertSame('verified', $transfer->state);
        $this->assertTrue((bool) $operation->journalEntries()->where('event', 'asset_transfer_verified')->first()?->payload['resumed_after_copy']);
    }

    #[Test]
    public function it_never_overwrites_or_deletes_unverified_preexisting_destination_bytes(): void
    {
        $operation = $this->createHistoricImportOperation();
        Storage::disk('historic_transfer_source')->put('bundle/audio.mp3', 'approved');
        Storage::disk('historic_transfer_destination')->put('received/audio.mp3', 'foreign');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refusing to overwrite');

        try {
            app(HistoricImportAssetTransferService::class)->transfer(
                $operation,
                'historic_transfer_source',
                'bundle/audio.mp3',
                'historic_transfer_destination',
                'received/audio.mp3',
                8,
                hash('sha256', 'approved'),
            );
        } finally {
            $this->assertSame('foreign', Storage::disk('historic_transfer_destination')->get('received/audio.mp3'));
        }
    }
}
