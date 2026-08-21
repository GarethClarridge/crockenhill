<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Email\AdjudicateOosSemanticEvaluationCorpus;
use App\Support\CanonicalJson;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Build the private -adjudicated semantic evaluation corpus from maintainer decisions recorded
 * over the frozen -prefilled worksheet.
 *
 * Delete this one-shot command alongside the rest of the Delivery 0 truth tooling when the
 * historic import IC8 closeout.
 *
 * Retention amended 2026-08-20: the Delivery 6 comparison artifact is now accepted, which under the
 * original wording would have made this deletable immediately. It is not. §9.12 requires
 * `outcome_rate` to be re-read on any future arm rather than treated as settled, and the accepted
 * 28.9% has a named remedy (the item-kind arm) that needs this surface to execute. Deleting on
 * Delivery 6 acceptance would strand the plan without the tooling to act on its own caveat, so the
 * trigger is now historic-import IC8 closeout only.
 */
class AdjudicateOosSemanticEvaluationCorpusCommand extends Command
{
    protected $signature = 'oos:adjudicate-semantic-corpus
        {--corpus= : Frozen private -prefilled semantic evaluation corpus}
        {--decisions= : Private JSON map of item_key to adjudication decision}
        {--output= : Absolute private JSON output path}';

    protected $description = 'Build the private adjudicated semantic evaluation corpus from maintainer decisions';

    public function handle(AdjudicateOosSemanticEvaluationCorpus $adjudicator): int
    {
        try {
            $corpusPath = $this->requiredPath('corpus');
            $decisionsPath = $this->requiredPath('decisions');
            $outputPath = $this->requiredPath('output', absolute: true);

            if (is_file($outputPath)) {
                throw new RuntimeException("Refusing to overwrite existing adjudicated corpus {$outputPath}.");
            }

            $artifact = $adjudicator->build($this->json($corpusPath), $this->json($decisionsPath));
            $json = CanonicalJson::encodeReadable($artifact);

            if (file_put_contents($outputPath, $json.PHP_EOL) === false || ! chmod($outputPath, 0600)) {
                throw new RuntimeException("Could not write private adjudicated corpus {$outputPath}.");
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $completeness = $artifact['completeness'];
        $this->info("Wrote adjudicated corpus to {$outputPath}.");
        $this->line("Fully adjudicated: {$completeness['fully_adjudicated_sources']}/{$completeness['source_count']}; scoreable: ".($completeness['scoreable'] ? 'true' : 'false'));
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

        return str_starts_with($value, '/') ? $value : base_path($value);
    }
}
