<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\HistoricImportAssetTransfer;
use App\Models\HistoricImportCheckpoint;
use App\Models\HistoricImportOperation;
use App\Support\CanonicalJson;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class HistoricImportAssetTransferService
{
    public function __construct(
        private readonly HistoricImportJournal $journal,
    ) {}

    public function transfer(
        HistoricImportOperation $operation,
        string $sourceDisk,
        string $sourcePath,
        string $destinationDisk,
        string $destinationPath,
        int $byteSize,
        string $sha256,
        ?HistoricImportCheckpoint $checkpoint = null,
    ): HistoricImportAssetTransfer {
        $this->assertInput($operation, $checkpoint, $sourceDisk, $destinationDisk, $byteSize, $sha256);
        $source = Storage::disk($sourceDisk);
        $destination = Storage::disk($destinationDisk);
        $this->verify($source, $sourcePath, $byteSize, $sha256, 'source');
        $transferKey = CanonicalJson::hash([
            'operation_id' => $operation->operation_id,
            'source_disk' => $sourceDisk,
            'source_path' => $sourcePath,
            'destination_disk' => $destinationDisk,
            'destination_path' => $destinationPath,
            'byte_size' => $byteSize,
            'sha256' => $sha256,
        ]);

        $transfer = DB::transaction(function () use (
            $operation,
            $checkpoint,
            $transferKey,
            $sourceDisk,
            $sourcePath,
            $destinationDisk,
            $destinationPath,
            $byteSize,
            $sha256,
        ): HistoricImportAssetTransfer {
            $existing = HistoricImportAssetTransfer::query()
                ->where('historic_import_operation_id', $operation->id)
                ->where('transfer_key', $transferKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof HistoricImportAssetTransfer) {
                $this->assertSameTransfer($existing, $checkpoint);

                return $existing;
            }

            $transfer = HistoricImportAssetTransfer::query()->create([
                'historic_import_operation_id' => $operation->id,
                'historic_import_checkpoint_id' => $checkpoint?->id,
                'transfer_key' => $transferKey,
                'source_disk' => $sourceDisk,
                'source_path' => $sourcePath,
                'destination_disk' => $destinationDisk,
                'destination_path' => $destinationPath,
                'byte_size' => $byteSize,
                'sha256' => $sha256,
                'state' => 'started',
                'attempts' => 1,
                'started_at' => now(),
                'retain_until' => now()->addDays((int) config('media-processing.historic_import.transfer_retention_days', 30)),
            ]);
            $this->journal->append($operation, 'asset_transfer_started', [
                'transfer_key' => $transferKey,
                'source_disk' => $sourceDisk,
                'source_path' => $sourcePath,
                'destination_disk' => $destinationDisk,
                'destination_path' => $destinationPath,
                'byte_size' => $byteSize,
                'sha256' => $sha256,
            ], $checkpoint);

            return $transfer;
        });

        if ($transfer->state === 'verified') {
            $this->verify($destination, $destinationPath, $byteSize, $sha256, 'destination');

            return $transfer;
        }

        if ($destination->exists($destinationPath)) {
            try {
                $this->verify($destination, $destinationPath, $byteSize, $sha256, 'destination');

                return $this->markVerified($transfer, $operation, $checkpoint, resumed: true);
            } catch (RuntimeException $exception) {
                throw new RuntimeException(
                    'Historic transfer destination exists with foreign or corrupt bytes; refusing to overwrite it.',
                    previous: $exception,
                );
            }
        }

        $stream = $source->readStream($sourcePath);

        if (! is_resource($stream)) {
            throw new RuntimeException('Historic transfer source could not be opened as a stream.');
        }

        try {
            if (! $destination->writeStream($destinationPath, $stream, ['visibility' => 'private'])) {
                throw new RuntimeException('Historic transfer stream write failed.');
            }
        } finally {
            fclose($stream);
        }

        try {
            $this->verify($destination, $destinationPath, $byteSize, $sha256, 'destination');
        } catch (RuntimeException $exception) {
            $destination->delete($destinationPath);

            throw $exception;
        }

        return $this->markVerified($transfer, $operation, $checkpoint, resumed: false);
    }

    private function markVerified(
        HistoricImportAssetTransfer $transfer,
        HistoricImportOperation $operation,
        ?HistoricImportCheckpoint $checkpoint,
        bool $resumed,
    ): HistoricImportAssetTransfer {
        return DB::transaction(function () use ($transfer, $operation, $checkpoint, $resumed): HistoricImportAssetTransfer {
            $transfer = HistoricImportAssetTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            $transfer->state = 'verified';
            $transfer->verified_at = now();
            $transfer->save();
            $this->journal->append($operation, 'asset_transfer_verified', [
                'transfer_key' => $transfer->transfer_key,
                'byte_size' => $transfer->byte_size,
                'sha256' => $transfer->sha256,
                'resumed_after_copy' => $resumed,
            ], $checkpoint);

            return $transfer;
        });
    }

    private function assertInput(
        HistoricImportOperation $operation,
        ?HistoricImportCheckpoint $checkpoint,
        string $sourceDisk,
        string $destinationDisk,
        int $byteSize,
        string $sha256,
    ): void {
        if ($checkpoint !== null && $checkpoint->historic_import_operation_id !== $operation->id) {
            throw new RuntimeException('Historic transfer checkpoint belongs to another operation.');
        }

        if ($sourceDisk === '' || $destinationDisk === '' || $sourceDisk === $destinationDisk || $byteSize < 0
            || preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1) {
            throw new RuntimeException('Historic transfer identity is invalid.');
        }
    }

    private function assertSameTransfer(HistoricImportAssetTransfer $transfer, ?HistoricImportCheckpoint $checkpoint): void
    {
        if ($transfer->historic_import_checkpoint_id !== $checkpoint?->id) {
            throw new RuntimeException('Historic transfer key is already owned by another checkpoint.');
        }
    }

    private function verify(
        FilesystemAdapter $disk,
        string $path,
        int $byteSize,
        string $sha256,
        string $boundary,
    ): void {
        if (! $disk->exists($path) || $disk->size($path) !== $byteSize) {
            throw new RuntimeException("Historic transfer {$boundary} size does not match its inventory.");
        }

        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Historic transfer {$boundary} could not be opened for verification.");
        }

        $context = hash_init('sha256');

        try {
            hash_update_stream($context, $stream);
            $actual = hash_final($context);
        } finally {
            fclose($stream);
        }

        if (! hash_equals($sha256, $actual)) {
            throw new RuntimeException("Historic transfer {$boundary} hash does not match its inventory.");
        }
    }
}
