<?php

declare(strict_types=1);

namespace Tests\Feature\Config;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Guards the cross-file invariant that a storage path the application writes to
 * in production is persisted across deploys, and that the three files which
 * together make that true agree with each other.
 *
 * Production runs an image pinned to a git SHA, so anything written to a path
 * that is not a named volume lives in the container's writable layer and is
 * destroyed when the next deploy replaces the container. This has bitten twice:
 * `storage/app/livestream` (the original uploaded recordings) and the former
 * `storage/app/private` (children's-talk media and section-publication
 * previews, since moved to Spaces). Both failed silently — database rows
 * survive pointing at nothing, so listings render and only the asset routes
 * 404.
 *
 * Three files must agree for a path to be safe:
 *   - docker-compose.prod.yml  mounts the named volume AND declares it
 *   - Dockerfile               mkdir -p's it, so Docker seeds a new volume with
 *                              www:www ownership instead of creating it root-owned
 *   - entrypoint.sh            chowns/chmods it at boot, which is what makes
 *                              ownership right regardless of the order volume and
 *                              image directory came into existence
 *
 * Omitting any one of the three is a silent write failure rather than a loud
 * one, so this asserts all three rather than just the mount.
 */
class ProductionStoragePersistenceTest extends TestCase
{
    /**
     * Storage paths the application writes to in production, relative to the
     * project root. Add to this list when a new local write root appears — that
     * is the moment the mount is needed, not later.
     *
     * @var list<string>
     */
    private const array PERSISTED_STORAGE_PATHS = [
        'storage/app/public',
        'storage/app/temp',
        'storage/app/livewire-tmp',
        'storage/app/livestream',
        'storage/logs',
    ];

    #[Test]
    public function every_persisted_storage_path_is_mounted_as_a_named_volume(): void
    {
        $mounts = $this->appServiceMounts();

        foreach (self::PERSISTED_STORAGE_PATHS as $path) {
            $this->assertArrayHasKey(
                '/var/www/html/'.$path,
                $mounts,
                "docker-compose.prod.yml does not mount a volume at {$path}, so everything written there is destroyed on the next deploy.",
            );
        }
    }

    /**
     * The converse of the test above, so the list is the single source of truth
     * in both directions. Without this, a path the application has stopped
     * writing to keeps its mount, its `mkdir` and its chown indefinitely — which
     * is exactly what `storage/app/private` did once children's-talk media moved
     * to Spaces. A stale mount is not a data-loss bug, but it is four files
     * claiming a requirement that no longer exists.
     */
    #[Test]
    public function no_storage_path_is_mounted_that_the_application_no_longer_writes_to(): void
    {
        foreach ($this->appServiceMounts() as $containerPath => $volumeName) {
            $relative = str_replace('/var/www/html/', '', $containerPath);

            $this->assertContains(
                $relative,
                self::PERSISTED_STORAGE_PATHS,
                "Volume {$volumeName} is mounted at {$relative}, which is not a path the application writes to. Remove the mount, its declaration, its Dockerfile mkdir and its entrypoint chown/chmod together.",
            );
        }
    }

    #[Test]
    public function every_mounted_volume_is_declared_in_the_top_level_volumes_block(): void
    {
        $compose = $this->compose();
        $declared = array_keys((array) ($compose['volumes'] ?? []));

        foreach ($this->appServiceMounts() as $containerPath => $volumeName) {
            $this->assertContains(
                $volumeName,
                $declared,
                "Volume {$volumeName} is mounted at {$containerPath} but never declared, so compose cannot create it.",
            );
        }
    }

    #[Test]
    public function the_dockerfile_creates_every_persisted_storage_app_directory(): void
    {
        $dockerfile = $this->readProjectFile('Dockerfile');

        foreach ($this->storageAppPaths() as $path) {
            $this->assertStringContainsString(
                $path,
                $dockerfile,
                "The Dockerfile never creates {$path}, so Docker seeds its named volume root-owned and writes fail silently.",
            );
        }
    }

    #[Test]
    public function the_entrypoint_fixes_ownership_on_every_persisted_storage_path(): void
    {
        $entrypoint = $this->readProjectFile('docker/production/entrypoint.sh');

        [$chown, $chmod] = $this->entrypointOwnershipBlocks($entrypoint);

        foreach (self::PERSISTED_STORAGE_PATHS as $path) {
            $absolute = '/var/www/html/'.$path;

            $this->assertStringContainsString(
                $absolute,
                $chown,
                "entrypoint.sh does not chown {$path}; a root-owned mounted volume is a silent write failure.",
            );
            $this->assertStringContainsString(
                $absolute,
                $chmod,
                "entrypoint.sh does not chmod {$path}; a root-owned mounted volume is a silent write failure.",
            );
        }
    }

    /**
     * The original uploaded recordings are the one population whose loss is
     * unrecoverable: a derived asset can be regenerated only while its source
     * survives, so if this mount goes, nothing can be rebuilt. Asserted
     * separately from the list above so deleting the entry cannot quietly
     * disable the check.
     */
    #[Test]
    public function source_recordings_are_persisted_even_though_the_temp_volume_looks_like_it_covers_them(): void
    {
        $mounts = $this->appServiceMounts();

        $this->assertArrayHasKey('/var/www/html/storage/app/livestream', $mounts);
        $this->assertArrayHasKey(
            '/var/www/html/storage/app/temp',
            $mounts,
            'storage/app/temp holds derived processing artifacts and is a separate concern from livestream/.',
        );
        $this->assertNotSame(
            $mounts['/var/www/html/storage/app/livestream'],
            $mounts['/var/www/html/storage/app/temp'],
            'Uploads live under livestream/ and derived artifacts under temp/; one volume cannot stand in for the other.',
        );
    }

    /**
     * Container path => volume name, for the app service's named-volume mounts.
     *
     * @return array<string, string>
     */
    private function appServiceMounts(): array
    {
        $compose = $this->compose();
        $mounts = [];

        /** @var list<string> $volumes */
        $volumes = (array) ($compose['services']['app']['volumes'] ?? []);

        foreach ($volumes as $mount) {
            $parts = explode(':', $mount);

            if (count($parts) < 2) {
                continue;
            }

            [$source, $target] = $parts;

            // Bind mounts start with . or / and are not deploy-durable volumes.
            if (str_starts_with($source, '.') || str_starts_with($source, '/')) {
                continue;
            }

            $mounts[$target] = $source;
        }

        return $mounts;
    }

    /** @return list<string> */
    private function storageAppPaths(): array
    {
        return array_values(array_filter(
            self::PERSISTED_STORAGE_PATHS,
            fn (string $path): bool => str_starts_with($path, 'storage/app/'),
        ));
    }

    /**
     * The chown and chmod argument lists, so a path appearing in one but not the
     * other is caught rather than passing on a substring match of the whole file.
     *
     * @return array{string, string}
     */
    private function entrypointOwnershipBlocks(string $entrypoint): array
    {
        $chownStart = strpos($entrypoint, 'chown -R www:www');
        $chmodStart = strpos($entrypoint, 'chmod -R 775');

        $this->assertNotFalse($chownStart, 'entrypoint.sh no longer contains a chown -R www:www block.');
        $this->assertNotFalse($chmodStart, 'entrypoint.sh no longer contains a chmod -R 775 block.');
        $this->assertGreaterThan($chownStart, $chmodStart, 'Expected the chmod block to follow the chown block.');

        return [
            substr($entrypoint, $chownStart, $chmodStart - $chownStart),
            substr($entrypoint, $chmodStart),
        ];
    }

    /** @return array<string, mixed> */
    private function compose(): array
    {
        return (array) Yaml::parse($this->readProjectFile('docker-compose.prod.yml'));
    }

    private function readProjectFile(string $relativePath): string
    {
        $path = base_path($relativePath);

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
