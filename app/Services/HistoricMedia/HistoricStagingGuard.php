<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

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
        $configuration = config("filesystems.disks.{$disk}");

        if (! is_array($configuration)) {
            throw new RuntimeException("Historic staging disk '{$disk}' is not configured.");
        }

        $served = ($configuration['visibility'] ?? null) === 'public'
            || filled($configuration['url'] ?? null)
            || filled($configuration['cdn_endpoint'] ?? null);

        if ($served) {
            throw new RuntimeException(
                "Historic staging disk '{$disk}' is publicly served, so it cannot hold private staging output."
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
}
