<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Import\HistoricImportRuntimeEvidenceCollector;
use App\Services\Import\HistoricImportRuntimePreflight;
use App\Support\CanonicalJson;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Capture the runtime attestation a production historic import operation must present.
 *
 * `HistoricImportRuntimePreflight` validated this artifact from the start but nothing produced it,
 * so no production operation could be created at all. This is the missing producer.
 *
 * It observes the running system and refuses rather than guesses. The three inputs it demands are
 * the ones this process cannot honestly determine about itself: its own image digest, which the
 * Docker daemon holds, and how the object store's encryption at rest and in transit were confirmed,
 * which are properties of the bucket. Those notes are hashed into the artifact so the claim carries
 * its own provenance.
 *
 * The captured evidence is validated through the real preflight before it is written, so a file
 * this command produces can never fail at `historic-import:prepare-operation`.
 *
 * Delete alongside the rest of the one-shot historic import surface at IC8 closeout.
 */
class CaptureHistoricRuntimeEvidenceCommand extends Command
{
    protected $signature = 'historic-import:capture-runtime-evidence
        {--image-digest= : This container image as name@sha256:<64 hex>, from `docker image inspect --format "{{index .RepoDigests 0}}"`}
        {--at-rest-evidence= : How encryption at rest was confirmed for the sermon object store}
        {--in-transit-evidence= : How encryption in transit was confirmed for the sermon object store}
        {--output= : New permission-restricted JSON artifact path}';

    protected $description = 'Observe and retain the runtime evidence a production historic import operation requires';

    public function handle(
        HistoricImportRuntimeEvidenceCollector $collector,
        HistoricImportRuntimePreflight $preflight,
    ): int {
        try {
            $outputPath = $this->newOutputPath();

            $evidence = $collector->collect([
                'image_digest' => $this->requiredOption('image-digest'),
                'storage_at_rest_evidence' => $this->requiredOption('at-rest-evidence'),
                'storage_in_transit_evidence' => $this->requiredOption('in-transit-evidence'),
            ]);

            // Validated through the real preflight before retention: an artifact that cannot be
            // accepted is not evidence, and finding that out at operation time wastes the capture.
            $fingerprint = $preflight->fingerprint($evidence);

            $this->createOnce($outputPath, CanonicalJson::encodeReadable($evidence).PHP_EOL);

            $this->info('Runtime evidence captured and validated.');
            $this->line("Artifact: {$outputPath}");
            $this->line("Runtime fingerprint: {$fingerprint}");
            $this->newLine();
            $this->line('Pass it to prepare-operation with:');
            $this->line("  --runtime-evidence={$outputPath}");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function requiredOption(string $name): string
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("--{$name} is required; runtime evidence is never defaulted.");
        }

        return trim($value);
    }

    private function newOutputPath(): string
    {
        $path = $this->requiredOption('output');

        if (! str_starts_with($path, '/')) {
            throw new RuntimeException('Runtime evidence requires an absolute --output path.');
        }

        if (file_exists($path)) {
            throw new RuntimeException("Refusing to overwrite existing runtime evidence: {$path}.");
        }

        $directory = dirname($path);

        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new RuntimeException("Runtime evidence directory is not writable: {$directory}.");
        }

        return $path;
    }

    private function createOnce(string $path, string $contents): void
    {
        $handle = @fopen($path, 'x');

        if ($handle === false) {
            throw new RuntimeException("Unable to create runtime evidence artifact: {$path}.");
        }

        try {
            if (fwrite($handle, $contents) === false) {
                throw new RuntimeException("Unable to write runtime evidence artifact: {$path}.");
            }
        } finally {
            fclose($handle);
        }

        chmod($path, 0600);
    }
}
