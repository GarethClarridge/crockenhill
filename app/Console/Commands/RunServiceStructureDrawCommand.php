<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\HistoricStagingContext;
use App\Services\ChurchService\Structure\ServiceStructureDrawExecutor;
use App\Services\ChurchService\Structure\ServiceStructureEvaluationTelemetry;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Support\ServiceArtifactDisk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/** Worker subprocess for one isolated, immutable ensemble slot. */
class RunServiceStructureDrawCommand extends Command
{
    protected $signature = 'service:structure-draw {input} {output} {model} {inputHash} {context?} {artifactDisk?}';

    protected $description = 'Run one service structure vote in an isolated process';

    public function handle(
        ServiceStructureDrawExecutor $executor,
        HistoricStagingContextRegistry $registry,
    ): int {
        $contextPayload = $this->argument('context');
        $context = null;

        if (is_string($contextPayload) && $contextPayload !== '') {
            $decoded = json_decode(base64_decode($contextPayload, true) ?: '', true);

            if (! is_array($decoded)) {
                throw new RuntimeException('Invalid historic staging context for ensemble draw.');
            }

            $context = HistoricStagingContext::fromArray($decoded);
        }

        $work = function () use ($executor): int {
            $artifactDisk = ServiceArtifactDisk::name();

            if ($artifactDisk !== $this->argument('artifactDisk')) {
                throw new RuntimeException('Ensemble draw artifact disk differs from its parent manifest.');
            }

            $disk = Storage::disk($artifactDisk);
            $inputPath = (string) $this->argument('input');
            $outputPath = (string) $this->argument('output');

            if (! str_starts_with($inputPath, ServiceArtifactDisk::DURABLE_PREFIX)
                || ! str_starts_with($outputPath, ServiceArtifactDisk::DURABLE_PREFIX)) {
                throw new RuntimeException('Ensemble draw paths must be private service artifacts.');
            }

            $raw = $disk->get($inputPath);

            if (! is_string($raw)) {
                throw new RuntimeException('Ensemble input snapshot is missing on the configured artifact disk.');
            }

            if (hash('sha256', $raw) !== $this->argument('inputHash')) {
                throw new RuntimeException('Ensemble input snapshot hash differs from its manifest.');
            }

            $input = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($input)) {
                throw new RuntimeException('Ensemble input snapshot is not an object.');
            }

            $started = microtime(true);

            try {
                $draw = $executor->execute($input, (string) $this->argument('model'));
                $result = [
                    'status' => $draw['validation']->passed() ? 'valid' : 'invalid',
                    'raw' => $draw['raw']->toArray(),
                    'raw_response' => $draw['raw_response'],
                    'refined' => $draw['refined']->toArray(),
                    'validated' => $draw['validation']->structure->toArray(),
                    'hard_failures' => $draw['validation']->hardFailures,
                    'unmatched_oos_item_ids' => $draw['validation']->unmatchedOosItemIds,
                    'usage' => $draw['usage'],
                    'service_tier' => $draw['service_tier'],
                ];
            } catch (Throwable $exception) {
                $telemetry = app(ServiceStructureEvaluationTelemetry::class);
                $result = [
                    'status' => 'unavailable',
                    'error' => $exception->getMessage(),
                    'usage' => $telemetry->take() ?? 'unknown',
                    'service_tier' => $telemetry->takeServiceTier(),
                    'raw_response' => $telemetry->takeRawResponse(),
                ];
            }

            $result['model'] = (string) $this->argument('model');
            $result['input_hash'] = (string) $this->argument('inputHash');
            $result['artifact_disk'] = $artifactDisk;
            $result['elapsed_seconds'] = microtime(true) - $started;

            if (! $disk->put($outputPath, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), ServiceArtifactDisk::WRITE_OPTIONS)) {
                throw new RuntimeException('Could not bank ensemble draw result.');
            }

            return $result['status'] === 'unavailable' ? self::FAILURE : self::SUCCESS;
        };

        return $context instanceof HistoricStagingContext
            ? $registry->within($context, $work)
            : $work();
    }
}
