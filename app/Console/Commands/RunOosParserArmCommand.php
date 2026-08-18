<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\OosParserEvaluationArm;
use App\Services\Email\OosCurationEntryFactory;
use App\Services\Email\OosCurationManifest;
use App\Services\Email\OosParserArmRunner;
use App\Support\OpenAiRateLimitDiagnostics;
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
        {--price-snapshot= : Dated official price snapshot, hashed into the run manifest}
        {--output= : New private run-directory name beneath storage/scratch/oos-parser-evaluation}
        {--stability-only : Diagnostic mode: canary + stability replicate only (~60 calls), no corpus spend; writes a stability diagnostic instead of a projection}';

    protected $description = 'Run one frozen OoS parser model-evaluation arm against the rehearsal corpus';

    public function handle(
        OosCurationManifest $manifest,
        OosCurationEntryFactory $entryFactory,
        OosParserArmRunner $runner,
    ): int {
        try {
            $arm = OosParserEvaluationArm::fromName($this->requiredOption('arm'));
            $manifestPath = $this->requiredOption('manifest');
            $priceSnapshotPath = $this->requiredOption('price-snapshot');
            $stabilityOnly = (bool) $this->option('stability-only');
            // Every option's shape is checked before any file is opened, so a mistyped run-directory
            // name is reported as that rather than as a missing artifact somewhere else.
            $output = $this->outputDirectory($this->requiredOption('output'));
            $priceSnapshot = $this->priceSnapshot($priceSnapshotPath);
            $this->echoResolvedConfiguration($arm);

            $plan = $manifest->plan(
                storage_path(self::DefaultVerbatimRoot),
                storage_path(self::DefaultFormattedRoot),
                $manifestPath,
            );
            $snapshots = $manifest->snapshots(storage_path(self::DefaultVerbatimRoot), storage_path(self::DefaultFormattedRoot), $plan);
            $manifest->validateSnapshotsForDryRun($plan, $snapshots);

            $report = $runner->run($arm, $entryFactory->entries($plan, $snapshots), $plan->manifestHash, $manifestPath, $priceSnapshot, $stabilityOnly);

            mkdir($output, 0700, true);

            /*
             * The diagnostic is retained rather than printed and discarded. Its field-by-field
             * decomposition of the disagreeing replicate pairs is the whole point of the mode — a
             * console summary reports the rate the run already reported, and the question left open
             * is which fields produce it.
             */
            if ($stabilityOnly) {
                $this->writeCreateOnce("{$output}/stability-diagnostic.json", $report);
                chmod("{$output}/stability-diagnostic.json", 0600);
                $this->reportStability($report);
                $this->line("Stability diagnostic: {$output}/stability-diagnostic.json");

                return self::SUCCESS;
            }

            $this->writeCreateOnce("{$output}/raw-result-projection.json", $report);

            /*
             * The same array the projection carries, written out on its own so provenance can be
             * quoted without shipping the raw parse output beside it. It is a projection of one
             * value, not a second copy: nothing can compute it differently from what ran.
             */
            $this->writeCreateOnce("{$output}/run-manifest.json", $report['run_manifest']);

            chmod("{$output}/raw-result-projection.json", 0600);
            chmod("{$output}/run-manifest.json", 0600);
            $this->reportManifest($report['run_manifest']);
            $this->info("Arm {$arm->name} completed: {$report['source_count']} sources.");
            $this->line("Raw projection: {$output}/raw-result-projection.json");
            $this->line("Run manifest: {$output}/run-manifest.json");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $rateLimitHeaders = OpenAiRateLimitDiagnostics::fromChain($exception);

            if ($rateLimitHeaders !== null) {
                $this->warn('Rate limit response headers:');
                foreach ($rateLimitHeaders as $name => $value) {
                    $this->line("  {$name}: {$value}");
                }
            }

            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * The §5.1 echo: print what this process resolved, before a penny is spent.
     *
     * `OosParserEvaluationArm` refuses a mismatch on its own, so this is not the safety control —
     * it is the operator's chance to see that the config cache, `.env` and Sail's env propagation
     * did not quietly serve a different value than intended. A comparison that ran the same model
     * twice yields a perfect, meaningless "no difference", and it does so silently.
     */
    private function echoResolvedConfiguration(OosParserEvaluationArm $arm): void
    {
        $arm->apply();
        $configuration = $arm->resolvedConfiguration();

        $this->info("Arm {$arm->name} resolved in this process:");
        $this->table(['Setting', 'Resolved'], [
            ['model', $configuration['model']],
            ['configured reasoning effort', $configuration['configured_reasoning_effort']],
            ['effective reasoning effort', $configuration['effective_reasoning_effort']],
            ['max completion tokens', (string) config('service-tracking.email_parsing.max_completion_tokens')],
            ['extraction attempts', (string) config('service-tracking.email_parsing.extraction_attempts')],
            ['review threshold', (string) config('service-tracking.email_parsing.review_threshold')],
            ['auto-import threshold', (string) config('service-tracking.email_parsing.auto_import_threshold')],
            ['service tier', (string) config('openai.service_tier')],
            ['rehearsal database', (string) config('database.connections.rehearsal.database')],
        ]);
    }

    /** @param array<string, mixed> $manifest */
    private function reportManifest(array $manifest): void
    {
        /** @var array<string, mixed> $surface */
        $surface = $manifest['parser_surface'];
        /** @var array<string, mixed> $inputs */
        $inputs = $manifest['inputs'];

        $this->line("Parser surface: {$surface['hash']}");
        $this->line("Price snapshot: {$inputs['price_snapshot_sha256']}");
        $this->line('Application commit: '.($manifest['application_commit'] ?? 'not determinable'));
    }

    /** @param array<string, mixed> $report */
    private function reportStability(array $report): void
    {
        /** @var array<string, mixed> $stability */
        $stability = $report['stability'];
        $rate = (float) $stability['rate'];

        /** @var array<string, int> $decomposition */
        $decomposition = $stability['field_decomposition'];

        $this->info("Arm {$report['arm']} stability diagnostic ({$report['model']}, returned {$report['returned_model']}):");
        $this->line("  Sample size: {$stability['sample_size']}");
        $this->line("  Self-disagreements: {$stability['self_disagreements']}");
        $this->line('  Rate: '.number_format($rate * 100, 1).'%');
        $this->line('  Disagreeing pairs by field group (a pair may count in several):');

        foreach ($decomposition as $group => $count) {
            $this->line("    {$group}: {$count}");
        }

        $this->line('No corpus projection was written — this is a diagnostic run only.');
    }

    /**
     * Prices are a dated design input, never the billing authority, and they are taken *before* the
     * arms are frozen so a favourable figure cannot be fitted afterwards. Requiring the file here
     * rather than at comparison time is what dates it to the run.
     *
     * @return array<string, mixed>
     */
    private function priceSnapshot(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("No price snapshot at {$path}.");
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException("The price snapshot at {$path} is not a JSON object.");
        }

        $models = $decoded['models'] ?? null;

        if (! is_array($models) || $models === []) {
            throw new RuntimeException('The price snapshot carries no models block.');
        }

        foreach (['input', 'output'] as $field) {
            foreach ($models as $model => $prices) {
                if (! is_array($prices) || ! is_numeric($prices[$field] ?? null)) {
                    throw new RuntimeException("The price snapshot is missing a usable {$field} price for {$model}.");
                }
            }
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
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
