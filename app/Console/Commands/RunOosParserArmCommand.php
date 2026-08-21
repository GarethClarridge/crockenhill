<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\OosParserEvaluationArm;
use App\Services\Email\OosCurationEntryFactory;
use App\Services\Email\OosCurationManifest;
use App\Services\Email\OosParserArmRunner;
use App\Support\CanonicalJson;
use App\Support\OpenAiRateLimitDiagnostics;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Run one frozen OoS parser model-evaluation arm on a certified rehearsal database.
 *
 * Delete at historic-import IC8 closeout. The former "once the Luna adoption report is accepted"
 * trigger is stale: that decision is closed, but the parser plan was amended to retain the
 * evaluation machinery through IC8, so IC8 is the only trigger.
 */
class RunOosParserArmCommand extends Command
{
    private const DefaultVerbatimRoot = 'scratch/oos-verbatim';

    private const DefaultFormattedRoot = 'scratch/oos';

    /** Mirrors the runner's own default, so the echoed configuration matches what will run. */
    private const DefaultStabilitySample = 30;

    protected $signature = 'service-tracking:run-oos-parser-arm
        {--arm= : Frozen arm name}
        {--manifest= : Approved OoS curation manifest}
        {--price-snapshot= : Dated official price snapshot, hashed into the run manifest}
        {--output= : New private run-directory name beneath storage/scratch/oos-parser-evaluation}
        {--stability-only : Diagnostic mode: canary + stability replicate only, no corpus spend; writes a stability diagnostic instead of a projection}
        {--stability-sample= : Sources the stability replicate draws, two calls each (default 30). The sample is nested, so a larger run stays comparable to a smaller one on the same manifest}';

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
            $stabilitySample = $this->stabilitySampleSize();
            // Every option's shape is checked before any file is opened, so a mistyped run-directory
            // name is reported as that rather than as a missing artifact somewhere else.
            $output = $this->outputDirectory($this->requiredOption('output'));
            $priceSnapshot = $this->priceSnapshot($priceSnapshotPath);
            $this->echoResolvedConfiguration($arm, $stabilitySample);

            $plan = $manifest->plan(
                storage_path(self::DefaultVerbatimRoot),
                storage_path(self::DefaultFormattedRoot),
                $manifestPath,
            );
            $snapshots = $manifest->snapshots(storage_path(self::DefaultVerbatimRoot), storage_path(self::DefaultFormattedRoot), $plan);
            $manifest->validateSnapshotsForDryRun($plan, $snapshots);

            $report = $runner->run($arm, $entryFactory->entries($plan, $snapshots), $plan->manifestHash, $manifestPath, $priceSnapshot, $stabilityOnly, $stabilitySample);

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
    private function echoResolvedConfiguration(OosParserEvaluationArm $arm, int $stabilitySample): void
    {
        $arm->apply();
        $configuration = $arm->resolvedConfiguration();

        $this->info("Arm {$arm->name} resolved in this process:");
        $this->table(['Setting', 'Resolved'], [
            ['model', $configuration['model']],
            ['configured reasoning effort', $configuration['configured_reasoning_effort']],
            ['effective reasoning effort', $configuration['effective_reasoning_effort']],
            // Both, because the name is what the operator typed and the hash is what will be sent.
            ['prompt variant', $configuration['prompt_variant']],
            ['prompt sha256', $configuration['prompt_sha256']],
            ['max completion tokens', (string) config('service-tracking.email_parsing.max_completion_tokens')],
            ['extraction attempts', (string) config('service-tracking.email_parsing.extraction_attempts')],
            ['review threshold', (string) config('service-tracking.email_parsing.review_threshold')],
            ['auto-import threshold', (string) config('service-tracking.email_parsing.auto_import_threshold')],
            ['service tier', (string) config('openai.service_tier')],
            ['rehearsal database', (string) config('database.connections.rehearsal.database')],
            // Two billed calls per sampled source, so this is a spend the operator is agreeing to.
            ['stability sample', (string) $stabilitySample],
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

        if ($stability['sample_size'] !== $stability['requested_sample_size']) {
            $this->warn("  Requested {$stability['requested_sample_size']}, but the corpus holds only {$stability['sample_size']} sources.");
        }

        $this->line("  Self-disagreements: {$stability['self_disagreements']}");
        $this->line('  Rate: '.number_format($rate * 100, 1).'%');

        /*
         * Deliberately not a verdict. The rule this rate is judged against lives in
         * OosParserArmPrimaryComparison, and this evaluation has twice been damaged by a threshold
         * restated beside the thing it gates rather than read from it. A rate printed here is an
         * observation; the comparator decides what it means.
         */
        $this->line('  This is an observation, not a verdict — the ceiling is applied by the arm comparison.');
        $this->reportValidationCensus($stability);
        $this->line('  Disagreeing pairs by field group (a pair may count in several):');

        foreach ($decomposition as $group => $count) {
            $this->line("    {$group}: {$count}");
        }

        $retained = count((array) $stability['disagreements']);

        $this->line($retained < (int) $stability['self_disagreements']
            ? "  Retained diffs: {$retained} of {$stability['self_disagreements']} (capped at {$stability['retained_difference_limit']}) — a sample, not a census."
            : "  Retained diffs: {$retained} — every disagreeing pair.");

        $this->line('No corpus projection was written — this is a diagnostic run only.');
    }

    /**
     * The first-pass failure profile and what correction did about it.
     *
     * Printed with its denominator on the same screen, because the unit here is a parse and the
     * unit two lines above is a source — the replicate parses every sampled source twice, so a
     * reader comparing the two rates without the denominators would halve one of them.
     *
     * @param  array<string, mixed>  $stability
     */
    private function reportValidationCensus(array $stability): void
    {
        /** @var array<string, mixed> $validation */
        $validation = $stability['validation'];
        /** @var array{content:array<string,int>,bookkeeping:array<string,int>} $firstPassCodes */
        $firstPassCodes = $validation['first_pass_rule_codes'];
        /** @var array<string, int> $outcomes */
        $outcomes = $validation['correction_outcomes'];

        $this->line("  Validation census over {$validation['parse_count']} parses (each sampled source twice):");
        $this->line("    First-pass failures: {$validation['first_pass_failure_parses']} ("
            .number_format((float) $validation['first_pass_failure_rate'] * 100, 1).'%)');
        $this->line("    Corrections attempted: {$validation['corrected_parses']} ("
            .number_format((float) $validation['correction_rate'] * 100, 1).'%)');

        foreach (['content', 'bookkeeping'] as $family) {
            $this->line("    First-pass {$family} rules:".($firstPassCodes[$family] === [] ? ' none' : ''));

            foreach ($firstPassCodes[$family] as $code => $count) {
                $this->line("      {$code}: {$count}");
            }
        }

        $this->line('    Correction outcomes:');

        foreach ($outcomes as $outcome => $count) {
            $this->line("      {$outcome}: {$count}");
        }

        if ((int) $validation['correction_call_failures'] > 0) {
            $this->warn("    Corrective calls that threw: {$validation['correction_call_failures']} — these carry no diagnosis and are in none of the outcomes above.");
        }
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

    /**
     * Two calls per sampled source are billed, so this is the run's cost, and a mistyped value is
     * worth refusing rather than rounding into a number that quietly changes what the run measures.
     */
    private function stabilitySampleSize(): int
    {
        $value = $this->option('stability-sample');

        if ($value === null || $value === '') {
            return self::DefaultStabilitySample;
        }

        if (preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            throw new RuntimeException('--stability-sample must be a positive whole number of sources.');
        }

        return (int) $value;
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
            fwrite($handle, CanonicalJson::encodeReadable($report).PHP_EOL);
        } finally {
            fclose($handle);
        }
    }
}
