<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Email\OosSemanticCorrectnessScorer;
use App\Services\Email\RunOosSemanticSafetyFixtures;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Score one semantic-parser candidate against the adjudicated private truth corpus.
 *
 * This calls no model and writes no production state: it reads a candidate evidence artifact that a
 * paid arm already produced, runs the deterministic §6.3 safety fixtures in process, and writes a
 * private scoring artifact. Delete this one-shot command at historic import IC8 closeout.
 *
 * Retention amended 2026-08-20: the Delivery 6 comparison artifact is now accepted, which under the
 * original wording would have made this deletable immediately. It is not. §9.12 requires
 * `outcome_rate` to be re-read on any future arm rather than treated as settled, and the accepted
 * 28.9% has a named remedy (the item-kind arm) that needs this surface to execute. Deleting on
 * Delivery 6 acceptance would strand the plan without the tooling to act on its own caveat, so the
 * trigger is now historic-import IC8 closeout only.
 */
class ScoreOosSemanticCandidateCommand extends Command
{
    protected $signature = 'oos:score-semantic-candidate
        {--corpus= : Fully adjudicated private semantic evaluation corpus}
        {--candidate= : Candidate evidence artifact to score}
        {--baseline-stability= : Banked legacy stability diagnostic the corpus was frozen against}
        {--replicate= : Optional second candidate evidence artifact, for replicate self-disagreement}
        {--output= : Absolute private JSON output path}';

    protected $description = 'Score a semantic-parser candidate against private truth and report every acceptance gate';

    public function handle(OosSemanticCorrectnessScorer $scorer, RunOosSemanticSafetyFixtures $fixtures): int
    {
        try {
            $corpusPath = $this->requiredPath('corpus');
            $candidatePath = $this->requiredPath('candidate');
            $baselinePath = $this->requiredPath('baseline-stability');
            $outputPath = $this->requiredPath('output', absolute: true);
            $replicatePath = $this->option('replicate');

            if (is_file($outputPath)) {
                throw new RuntimeException("Refusing to overwrite existing scoring artifact {$outputPath}.");
            }

            $report = $scorer->score(
                $this->json($corpusPath),
                $this->json($candidatePath),
                $this->json($baselinePath),
                $fixtures->run(),
                is_string($replicatePath) && $replicatePath !== '' ? $this->json($this->path($replicatePath)) : null,
            );
            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            if (file_put_contents($outputPath, $json.PHP_EOL) === false || ! chmod($outputPath, 0600)) {
                throw new RuntimeException("Could not write private scoring artifact {$outputPath}.");
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Wrote scoring artifact to {$outputPath}.");

        if ($report['inference']['label'] === 'refused') {
            $this->warn('No verdict was produced. Refusals:');

            foreach ($report['inference']['refusals'] as $refusal) {
                $this->line("  - {$refusal}");
            }

            return self::FAILURE;
        }

        $this->line("Verdict: {$report['verdict']}");

        foreach ($report['gates'] as $gate) {
            $this->line(sprintf('  gate %2d  %-46s %s', $gate['gate'], $gate['name'], strtoupper((string) $gate['status'])));
        }

        return $report['verdict'] === 'fail' ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function json(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("{$path} does not exist.");
        }

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

    private function path(string $value): string
    {
        return str_starts_with($value, '/') ? $value : base_path($value);
    }
}
