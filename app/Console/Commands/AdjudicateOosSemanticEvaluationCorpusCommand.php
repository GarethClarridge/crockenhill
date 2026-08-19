<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Email\AdjudicateOosSemanticEvaluationCorpus;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Build the private -adjudicated semantic evaluation corpus from maintainer decisions recorded
 * over the frozen -prefilled worksheet.
 *
 * Delete this one-shot command alongside the rest of the Delivery 0 truth tooling when the
 * Delivery 6 comparison artifact is accepted, or at historic import IC8 closeout at the latest.
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
            $json = json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

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
