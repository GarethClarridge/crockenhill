<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Email\FreezeOosSemanticEvaluationCorpus;
use App\Services\Email\OosCurationEntryFactory;
use App\Services\Email\OosCurationManifest;
use App\Support\CanonicalJson;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Freeze the private semantic-parser truth worksheet and legacy compatibility baseline.
 *
 * Delete this one-shot command at historic import IC8 closeout.
 *
 * Retention amended 2026-08-20: the Delivery 6 comparison artifact is now accepted, which under the
 * original wording would have made this deletable immediately. It is not. §9.12 requires
 * `outcome_rate` to be re-read on any future arm rather than treated as settled, and the accepted
 * 28.9% has a named remedy (the item-kind arm) that needs this surface to execute. Deleting on
 * Delivery 6 acceptance would strand the plan without the tooling to act on its own caveat, so the
 * trigger is now historic-import IC8 closeout only.
 * The permanent parser contracts and compact synthetic fixtures
 * remain.
 */
class FreezeOosSemanticEvaluationCorpusCommand extends Command
{
    protected $signature = 'oos:freeze-semantic-evaluation-corpus
        {--manifest= : Approved OoS curation manifest}
        {--verbatim=storage/scratch/oos-verbatim : Verbatim corpus root}
        {--formatted=storage/scratch/oos : Formatted corpus root}
        {--item-truth= : Refreshed IC3 item-truth artifact}
        {--legacy-projection= : Banked legacy raw-result projection}
        {--stability-diagnostic= : Banked deterministic stability diagnostic}
        {--hard-case=* : Additional approved item key to include}
        {--output= : Absolute private JSON output path}';

    protected $description = 'Freeze the private source-faithful semantic parser evaluation worksheet';

    public function handle(
        OosCurationManifest $manifest,
        OosCurationEntryFactory $entryFactory,
        FreezeOosSemanticEvaluationCorpus $freezer,
    ): int {
        try {
            $manifestPath = $this->requiredPath('manifest');
            $itemTruthPath = $this->requiredPath('item-truth');
            $legacyPath = $this->requiredPath('legacy-projection');
            $stabilityPath = $this->requiredPath('stability-diagnostic');
            $outputPath = $this->requiredPath('output', absolute: true);

            if (is_file($outputPath)) {
                throw new RuntimeException("Refusing to overwrite existing evaluation corpus {$outputPath}.");
            }

            $verbatim = $this->path((string) $this->option('verbatim'));
            $formatted = $this->path((string) $this->option('formatted'));
            $plan = $manifest->plan($verbatim, $formatted, $manifestPath);
            $snapshots = $manifest->snapshots($verbatim, $formatted, $plan);
            $artifact = $freezer->build(
                $entryFactory->entries($plan, $snapshots),
                $this->json($legacyPath),
                $this->json($stabilityPath),
                $this->json($itemTruthPath),
                $this->hardCases(),
            );

            $json = CanonicalJson::encodeReadable($artifact);

            if (file_put_contents($outputPath, $json.PHP_EOL) === false || ! chmod($outputPath, 0600)) {
                throw new RuntimeException("Could not write private evaluation corpus {$outputPath}.");
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Frozen {$artifact['completeness']['source_count']} private source(s) at {$outputPath}.");
        $this->warn('Truth adjudication remains pending; this corpus is not yet scoreable and must not trigger paid evaluation.');
        $this->line("Corpus hash: {$artifact['corpus_hash']}");

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function json(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException("{$path} is not a JSON object.");
        }

        return $decoded;
    }

    private function requiredPath(string $option, bool $absolute = false): string
    {
        $value = $this->option($option);

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("--{$option} is required.");
        }

        if ($absolute && ! str_starts_with($value, '/')) {
            throw new RuntimeException("--{$option} must be an absolute path.");
        }

        return $this->path($value);
    }

    private function path(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    /** @return list<string> */
    private function hardCases(): array
    {
        $values = $this->option('hard-case');

        return array_values(array_unique(array_filter(
            $values,
            static fn (?string $value): bool => $value !== null && $value !== '',
        )));
    }
}
