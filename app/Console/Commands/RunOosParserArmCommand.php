<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\OosParserEvaluationArm;
use App\Services\Email\OosCurationEntryFactory;
use App\Services\Email\OosCurationManifest;
use App\Services\Email\OosParserArmRunner;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Run one frozen OoS parser model-evaluation arm on a certified rehearsal database.
 *
 * Delete once the Luna adoption report is accepted and no rerun remains, or at historic-import
 * IC8 closeout at the latest.
 */
class RunOosParserArmCommand extends Command
{
    private const DefaultVerbatimRoot = 'scratch/oos-verbatim';

    private const DefaultFormattedRoot = 'scratch/oos';

    protected $signature = 'service-tracking:run-oos-parser-arm
        {--arm= : Frozen arm name}
        {--manifest= : Approved OoS curation manifest}
        {--output= : New private run-directory name beneath storage/scratch/oos-parser-evaluation}';

    protected $description = 'Run one frozen OoS parser model-evaluation arm against the rehearsal corpus';

    public function handle(
        OosCurationManifest $manifest,
        OosCurationEntryFactory $entryFactory,
        OosParserArmRunner $runner,
    ): int {
        try {
            $arm = OosParserEvaluationArm::fromName($this->requiredOption('arm'));
            $manifestPath = $this->requiredOption('manifest');
            $output = $this->outputDirectory($this->requiredOption('output'));

            $plan = $manifest->plan(
                storage_path(self::DefaultVerbatimRoot),
                storage_path(self::DefaultFormattedRoot),
                $manifestPath,
            );
            $snapshots = $manifest->snapshots(storage_path(self::DefaultVerbatimRoot), storage_path(self::DefaultFormattedRoot), $plan);
            $manifest->validateSnapshotsForDryRun($plan, $snapshots);

            mkdir($output, 0700, true);
            $report = $runner->run($arm, $entryFactory->entries($plan, $snapshots), $plan->manifestHash, $manifestPath);
            $this->writeCreateOnce("{$output}/raw-result-projection.json", $report);

            chmod("{$output}/raw-result-projection.json", 0600);
            $this->info("Arm {$arm->name} completed: {$report['source_count']} sources.");
            $this->line("Raw projection: {$output}/raw-result-projection.json");

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
            throw new RuntimeException("--{$name} is required.");
        }

        return trim($value);
    }

    private function outputDirectory(string $name): string
    {
        if (basename($name) !== $name || ! preg_match('/\A[a-z0-9][a-z0-9-]*\z/', $name)) {
            throw new RuntimeException('--output must be a new lowercase run-directory name.');
        }

        $directory = storage_path("scratch/oos-parser-evaluation/{$name}");

        if (file_exists($directory)) {
            throw new RuntimeException("Refusing to overwrite existing evaluation output {$name}.");
        }

        return $directory;
    }

    /** @param array<string, mixed> $report */
    private function writeCreateOnce(string $path, array $report): void
    {
        $handle = fopen($path, 'x');

        if ($handle === false) {
            throw new RuntimeException("Refusing to overwrite {$path}.");
        }

        try {
            fwrite($handle, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
        } finally {
            fclose($handle);
        }
    }
}
