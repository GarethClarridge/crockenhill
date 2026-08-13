<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Services\HistoricMedia\HistoricProcessingResultAssetTransfer;
use App\Support\CanonicalJson;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * The whole operation binding: stable resources *and* the release, schema and
 * configuration pointed at them.
 *
 * Both halves are load-bearing and they answer different questions. This hash
 * answers "is this still the approved operation?", which is why preparation,
 * approval, closeout and release authority all bind to it and why it must keep
 * changing when a release or migration does.
 *
 * It is deliberately **not** what decides whether a target is production. HIR1
 * split that out into {@see HistoricImportResourceIdentity} after the guard's
 * use of this hash was found to fail open under exactly the drift this hash is
 * designed to notice.
 */
final class HistoricImportTargetFingerprint
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly HistoricProcessingResultAssetTransfer $assets,
        private readonly HistoricImportResourceIdentity $resources,
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
            'database' => $this->resources->database(),
            'storage' => [
                ...$this->assets->storageIdentity(),
                // Recorded, not enforced. HIR-D2 demoted the storage anchor from
                // a production trigger; keeping it in the binding is what lets
                // an operator and HIR7 see which store an operation resolved.
                'public' => $this->resources->storage(),
            ],
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
