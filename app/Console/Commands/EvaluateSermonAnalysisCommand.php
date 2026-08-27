<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sermon\SermonAnalysisEvaluationRunner;
use App\Support\CanonicalJson;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Run one report-only sermon-analysis model arm against banked transcripts for blinded review.
 *
 * The command requires the OpenAI analysis service and evaluation-arm environment overrides to be
 * set by the caller. It never changes application defaults, sermons or processing records.
 *
 * Delete this comparison-only command at historic-import IC8 closeout.
 */
class EvaluateSermonAnalysisCommand extends Command
{
    protected $signature = 'sermons:evaluate-analysis
        {--manifest= : Private manifest of banked sermon transcript files}
        {--arm= : Unique evaluation arm name, matching OPENAI_EVALUATION_ARM}
        {--price-snapshot= : Dated official OpenAI price snapshot JSON}
        {--delay=0 : Seconds to wait between calls; six back-to-back calls trip the provider rate limit}
        {--output= : New permission-restricted JSON report path}';

    protected $description = 'Run one report-only sermon-analysis model arm against banked transcripts';

    public function handle(SermonAnalysisEvaluationRunner $runner): int
    {
        try {
            $this->assertOpenAiConfiguration();
            $arm = $this->requiredOption('arm');
            $this->assertEvaluationArm($arm);
            $manifestPath = $this->requiredFileOption('manifest');
            $priceSnapshot = $this->readPriceSnapshot();
            $outputPath = $this->newOutputPath();

            $report = $runner->run($manifestPath, $arm, $priceSnapshot, max(0, (int) $this->option('delay')));
            $this->createOnce($outputPath, CanonicalJson::encodeReadable($report).PHP_EOL);

            $this->line("Report: {$outputPath}");
            $this->line('Manifest sha256: '.$report['manifest_sha256']);
            $this->line('Corpus hash: '.$report['corpus_hash']);
            $this->line(sprintf(
                'Completed %d/%d transcript(s); p50/p95 wall time %d/%d ms.',
                $report['summary']['success_count'],
                $report['transcript_count'],
                $report['summary']['wall_time_p50_ms'] ?? 0,
                $report['summary']['wall_time_p95_ms'] ?? 0,
            ));

            if ($report['summary']['failure_count'] > 0 || $report['summary']['usage_missing_count'] > 0) {
                $this->error('The evaluation arm is incomplete; inspect the retained report before scoring it.');

                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function assertOpenAiConfiguration(): void
    {
        if (config('media-processing.analysis.service') !== 'openai') {
            throw new RuntimeException('Run the evaluator with ANALYSIS_SERVICE=openai; the configured analysis service is not paid OpenAI.');
        }

        if (blank(config('media-processing.analysis.openai_api_key') ?? config('openai.api_key'))) {
            throw new RuntimeException('An OpenAI API key is required for sermon-analysis evaluation.');
        }
    }

    private function assertEvaluationArm(string $arm): void
    {
        $configuredArm = config('openai.evaluation_arm');

        if (! is_string($configuredArm) || trim($configuredArm) === '') {
            throw new RuntimeException('Run the evaluator with OPENAI_EVALUATION_ARM set.');
        }

        if (! hash_equals(trim($configuredArm), $arm)) {
            throw new RuntimeException("--arm {$arm} does not match OPENAI_EVALUATION_ARM {$configuredArm}.");
        }
    }

    /** @return array<string, mixed> */
    private function readPriceSnapshot(): array
    {
        $path = $this->requiredFileOption('price-snapshot');
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException("Unable to read price snapshot at {$path}.");
        }

        try {
            $snapshot = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $throwable) {
            throw new RuntimeException('The price snapshot is not valid JSON.', previous: $throwable);
        }

        if (! is_array($snapshot)) {
            throw new RuntimeException('The price snapshot must contain a JSON object.');
        }

        return $snapshot;
    }

    private function requiredFileOption(string $name): string
    {
        $path = $this->requiredOption($name);

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("--{$name} must point to a readable file.");
        }

        return $path;
    }

    private function requiredOption(string $name): string
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("--{$name} is required.");
        }

        return trim($value);
    }

    private function newOutputPath(): string
    {
        $path = $this->requiredOption('output');

        if (! str_starts_with($path, '/')) {
            throw new RuntimeException('A sermon-analysis evaluation requires an absolute --output path.');
        }

        if (file_exists($path)) {
            throw new RuntimeException("Refusing to overwrite existing evaluation report: {$path}.");
        }

        $directory = dirname($path);

        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new RuntimeException("Evaluation report directory is not writable: {$directory}.");
        }

        return $path;
    }

    private function createOnce(string $path, string $contents): void
    {
        $handle = @fopen($path, 'x');

        if ($handle === false) {
            throw new RuntimeException("Unable to create evaluation report: {$path}.");
        }

        try {
            if (fwrite($handle, $contents) === false) {
                throw new RuntimeException("Unable to write evaluation report: {$path}.");
            }
        } finally {
            fclose($handle);
        }

        chmod($path, 0600);
    }
}
