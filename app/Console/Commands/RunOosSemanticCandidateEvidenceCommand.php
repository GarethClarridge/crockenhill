<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Email\OosSemanticCandidateEvidenceRunner;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Run the paid semantic candidate once against fully adjudicated private truth.
 *
 * Delete this one-shot command when the Delivery 6 comparison artifact is accepted, or at historic
 * import IC8 closeout at the latest. It deliberately refuses incomplete truth before any model call.
 */
class RunOosSemanticCandidateEvidenceCommand extends Command
{
    protected $signature = 'oos:run-semantic-candidate-evidence
        {--corpus= : Fully adjudicated private semantic evaluation corpus}
        {--price-snapshot= : Dated official price snapshot}
        {--output= : Absolute private JSON output path}
        {--paid-model-approved : Confirm the maintainer approved paid model calls for this run}';

    protected $description = 'Run one correctness-first semantic-parser candidate evidence arm';

    public function handle(OosSemanticCandidateEvidenceRunner $runner): int
    {
        try {
            if (! $this->option('paid-model-approved')) {
                throw new RuntimeException('Paid model approval must be confirmed with --paid-model-approved.');
            }

            $corpusPath = $this->requiredPath('corpus');
            $pricePath = $this->requiredPath('price-snapshot');
            $outputPath = $this->requiredPath('output', absolute: true);

            if (is_file($outputPath)) {
                throw new RuntimeException("Refusing to overwrite existing candidate evidence {$outputPath}.");
            }

            $artifact = $runner->run($this->json($corpusPath), $this->json($pricePath));
            $json = json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            if (file_put_contents($outputPath, $json.PHP_EOL) === false || ! chmod($outputPath, 0600)) {
                throw new RuntimeException("Could not write private candidate evidence {$outputPath}.");
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Wrote candidate evidence to {$outputPath}.");
        $this->line("Returned model: {$artifact['candidate']['returned_model']}");
        $this->line("Calls: {$artifact['usage']['calls']}; tokens: {$artifact['usage']['total_tokens']}");
        $this->warn('This is raw evidence, not a passing verdict. Correctness scoring and every acceptance gate remain mandatory.');

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
