<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Data\HistoricStagingContext;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * HM1 storage isolation for the historic archive operation.
 *
 * Permanent media paths embed database IDs — `sermons/{sermon_id}/...`, published song
 * video paths carrying `service_section_id`, section-publication paths carrying local
 * section IDs. Local processing allocates those IDs from the local database, so writing
 * its output to production's media disk both collides with unrelated production rows and
 * leaves unpromoted objects publicly addressable under keys that belong to something
 * else.
 *
 * The historic batch therefore runs with the media disks pointed at a private staging
 * disk, and this guard refuses to start otherwise. Configuration is checked rather than
 * overridden per job: an override applied to some of the ~20 pipeline call sites and not
 * others would be worse than none at all.
 */
class HistoricStagingGuard
{
    /**
     * @var list<string>
     */
    private const MediaOutputKeys = ['sermon_disk', 'transcript_disk'];

    /**
     * Every storage destination a historic pipeline job may write. The context
     * swaps these as one set, so worker configuration cannot leak one output
     * family into a shared/public disk.
     *
     * @var list<string>
     */
    private const ContextStorageKeys = [
        'media-processing.storage.sermon_disk',
        'media-processing.storage.transcript_disk',
        'media-processing.storage.temp_disk',
        'thumbnail-generation.storage.disk',
        'thumbnail-generation.processing.temp_disk',
    ];

    /**
     * Refuse a local historic processing batch unless every media output disk is the
     * private staging disk.
     */
    public function assertLocalProcessingIsIsolated(): void
    {
        $staging = $this->stagingDisk();

        foreach (self::MediaOutputKeys as $key) {
            $configured = (string) config("media-processing.storage.{$key}", '');

            if ($configured === $staging) {
                continue;
            }

            throw new RuntimeException(
                "Historic processing would write {$key} to the '{$configured}' disk. Set it to the private ".
                "staging disk '{$staging}' before processing historic recordings, so local output cannot enter ".
                "production's canonical asset namespace."
            );
        }

        $this->assertNotPubliclyServed($staging);
    }

    /**
     * Refuse to treat a publicly served disk as private staging.
     */
    public function assertNotPubliclyServed(string $disk): void
    {
        if ($this->isPubliclyServed($disk)) {
            throw new RuntimeException(
                "Historic staging disk '{$disk}' is publicly served, so it cannot hold private staging output."
            );
        }

        foreach ((array) config('filesystems.disks', []) as $candidate => $configuration) {
            if (! is_string($candidate) || $candidate === $disk || ! is_array($configuration)) {
                continue;
            }

            if (! $this->isPubliclyServed($candidate) || ! $this->sameStorageIdentity($disk, $candidate)) {
                continue;
            }

            throw new RuntimeException(
                "Historic staging disk '{$disk}' resolves to publicly served disk '{$candidate}', so disk aliases cannot be used to bypass staging isolation.",
            );
        }
    }

    /**
     * Bundle A imports read every asset from the single staging disk. Refuse to
     * export a bundle whose source references cannot make that round trip.
     *
     * @param  list<string>  $disks
     */
    public function assertExportSourcesAreStaged(array $disks): void
    {
        $staging = $this->stagingDisk();

        foreach (array_unique($disks) as $disk) {
            if ($disk === $staging) {
                continue;
            }

            throw new RuntimeException(
                "Historic export source disk '{$disk}' is not the configured staging disk '{$staging}'. ".
                'Move legacy artifacts into staging before exporting Bundle A.'
            );
        }

        $this->assertNotPubliclyServed($staging);
    }

    public function stagingDisk(): string
    {
        $staging = (string) config('media-processing.storage.historic_staging_disk', '');

        if ($staging === '') {
            throw new RuntimeException('No historic staging disk is configured.');
        }

        return $staging;
    }

    public function contextForApprovedPlan(string $manifestHash, string $planHash): HistoricStagingContext
    {
        $this->assertLocalProcessingIsIsolated();

        $staging = $this->stagingDisk();
        $this->requireExplicitRoot($this->diskConfiguration($staging));

        return new HistoricStagingContext(
            manifestHash: $manifestHash,
            planHash: $planHash,
            stagingDisk: $staging,
            batchRoot: "historic-batches/{$planHash}",
            storageIdentity: $this->storageIdentity($staging),
        );
    }

    public function activate(HistoricStagingContext $context): HistoricStagingActivation
    {
        if ($context->stagingDisk !== $this->stagingDisk()) {
            throw new RuntimeException('Historic staging context names a different staging disk from this worker.');
        }

        $this->assertNotPubliclyServed($context->stagingDisk);

        if ($context->storageIdentity !== $this->storageIdentity($context->stagingDisk)) {
            throw new RuntimeException('Historic staging context does not match this worker storage identity. Restart workers with the approved storage configuration.');
        }

        $originalDiskConfiguration = $this->diskConfiguration($context->stagingDisk);
        $this->requireExplicitRoot($originalDiskConfiguration);
        $diskConfiguration = $originalDiskConfiguration;
        $storageConfiguration = [];

        foreach (self::ContextStorageKeys as $key) {
            $storageConfiguration[$key] = config($key);
            config([$key => $context->stagingDisk]);
        }

        $diskConfiguration = $this->withBatchRoot($diskConfiguration, $context->batchRoot);
        config(["filesystems.disks.{$context->stagingDisk}" => $diskConfiguration]);
        Storage::forgetDisk($context->stagingDisk);

        return new HistoricStagingActivation($context->stagingDisk, $originalDiskConfiguration, $storageConfiguration);
    }

    /** @return array{driver:string,bucket:?string,root_fingerprint:string,prefix_fingerprint:string} */
    private function storageIdentity(string $disk): array
    {
        $configuration = $this->diskConfiguration($disk);
        $root = $this->resolvedRoot($configuration);
        $prefix = (string) ($configuration['prefix'] ?? '');

        return [
            'driver' => (string) ($configuration['driver'] ?? ''),
            'bucket' => is_string($configuration['bucket'] ?? null) ? $configuration['bucket'] : null,
            'root_fingerprint' => hash('sha256', $root),
            'prefix_fingerprint' => hash('sha256', $prefix),
        ];
    }

    private function sameStorageIdentity(string $left, string $right): bool
    {
        return $this->storageIdentity($left) === $this->storageIdentity($right);
    }

    private function isPubliclyServed(string $disk): bool
    {
        $configuration = $this->diskConfiguration($disk);

        return ($configuration['visibility'] ?? null) === 'public'
            || filled($configuration['url'] ?? null)
            || filled($configuration['cdn_endpoint'] ?? null);
    }

    /** @return array<string, mixed> */
    private function diskConfiguration(string $disk): array
    {
        $configuration = config("filesystems.disks.{$disk}");

        if (! is_array($configuration)) {
            throw new RuntimeException("Historic staging disk '{$disk}' is not configured.");
        }

        return $configuration;
    }

    /** @param  array<string, mixed>  $configuration */
    private function resolvedRoot(array $configuration): string
    {
        $root = (string) ($configuration['root'] ?? '');

        if (($configuration['driver'] ?? null) !== 'local') {
            return trim($root, '/');
        }

        return realpath($root) ?: rtrim($root, '/');
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    private function withBatchRoot(array $configuration, string $batchRoot): array
    {
        $root = (string) ($configuration['root'] ?? '');

        if ($root !== '') {
            $configuration['root'] = $this->appendBatchRoot($root, $batchRoot);

            return $configuration;
        }

        $prefix = (string) ($configuration['prefix'] ?? '');

        if ($prefix !== '') {
            $configuration['prefix'] = $this->appendBatchRoot($prefix, $batchRoot);

            return $configuration;
        }

        throw new RuntimeException('Historic staging storage requires an explicit private root or prefix.');
    }

    private function appendBatchRoot(string $root, string $batchRoot): string
    {
        return rtrim($root, '/').'/'.ltrim($batchRoot, '/');
    }

    /** @param  array<string, mixed>  $configuration */
    private function requireExplicitRoot(array $configuration): void
    {
        if ((string) ($configuration['root'] ?? '') === '' && (string) ($configuration['prefix'] ?? '') === '') {
            throw new RuntimeException('Historic staging storage requires an explicit private root or prefix.');
        }
    }
}
