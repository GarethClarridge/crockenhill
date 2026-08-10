<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Services\HistoricMedia\HistoricProcessingResultAssetTransfer;
use App\Support\CanonicalJson;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final class HistoricImportTargetFingerprint
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly HistoricProcessingResultAssetTransfer $assets,
    ) {}

    /** @return array<string, mixed> */
    public function identity(): array
    {
        $release = config('app.release_identifier');
        $connection = $this->database->connection();

        if (! is_string($release) || trim($release) === '') {
            throw new RuntimeException('Historic import target fingerprint requires APP_RELEASE_IDENTIFIER.');
        }

        return [
            'database' => [
                'driver' => $connection->getDriverName(),
                'name_hash' => hash('sha256', $connection->getDatabaseName()),
            ],
            'storage' => $this->assets->storageIdentity(),
            'release_identifier' => trim($release),
            'schema' => [
                'migration_batch' => (int) $connection->table('migrations')->max('batch'),
                'migration_count' => $connection->table('migrations')->count(),
            ],
            'configuration' => [
                'public_service_cutoff' => config('church.services.public_from'),
                'service_structure_mode' => config('media-processing.service_structure.mode'),
                'transcription_service' => config('media-processing.transcription.service'),
            ],
        ];
    }

    public function hash(): string
    {
        return CanonicalJson::hash($this->identity());
    }
}
