<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Email\HistoricEmailContentCalibration;
use App\Support\CanonicalJson;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Fit and score IC3's report-only Email confidence baseline against item-level ground truth.
 *
 * Delete this one-shot command at IC8 historic-import closeout, with the other disposable
 * promotion and measurement tooling.
 */
class CalibrateHistoricEmailContentCommand extends Command
{
    protected $signature = 'service-tracking:calibrate-historic-email-content
        {--archive-report= : Path to an OoS archive evaluation report}
        {--ground-truth= : Path to an item-level ground-truth artifact}
        {--output= : Absolute path for the new calibration artifact}';

    protected $description = 'Fit a report-only Email confidence baseline against corroborated item-level ground truth';

    public function handle(HistoricEmailContentCalibration $calibration): int
    {
        try {
            $artifact = $calibration->calibrate(
                $this->readArtifact('archive-report'),
                $this->readArtifact('ground-truth'),
            );
            $path = $this->outputPath();
            $this->createOnce($path, CanonicalJson::encodeReadable($artifact).PHP_EOL);

            $this->line("Artifact: {$path}");
            $this->line('Artifact sha256: '.CanonicalJson::hash($artifact));
            $this->line('Live auto-import gate: unchanged');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @return array<string, mixed> */
    private function readArtifact(string $option): array
    {
        $path = $this->requiredOption($option);
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException("Unable to read {$option} at {$path}.");
        }

        try {
            $artifact = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException("The {$option} artifact is not valid JSON.");
        }

        if (! is_array($artifact)) {
            throw new RuntimeException("The {$option} artifact must contain a JSON object.");
        }

        return $artifact;
    }

    private function requiredOption(string $name): string
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("--{$name} is required.");
        }

        return trim($value);
    }

    private function outputPath(): string
    {
        $path = $this->requiredOption('output');

        if (! str_starts_with($path, '/')) {
            throw new RuntimeException('A calibration artifact requires an absolute --output path.');
        }

        return $path;
    }

    private function createOnce(string $path, string $contents): void
    {
        $handle = @fopen($path, 'x');

        if ($handle === false) {
            throw new RuntimeException("Refusing to overwrite an existing artifact at {$path}.");
        }

        try {
            if (fwrite($handle, $contents) === false) {
                throw new RuntimeException("Unable to write the artifact at {$path}.");
            }
        } finally {
            fclose($handle);
        }
    }
}
