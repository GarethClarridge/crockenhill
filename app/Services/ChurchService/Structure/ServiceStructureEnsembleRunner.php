<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ServiceStructure;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Support\ServiceArtifactDisk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/** Four independent worker processes over one content-hashed input snapshot. */
class ServiceStructureEnsembleRunner
{
    /** The kind recorded on each evidence file in the run's service artifact list. */
    public const ARTIFACT_KIND = 'service_structure_ensemble';

    public function __construct(
        private readonly ServiceStructureDrawExecutor $executor,
        private readonly HistoricStagingContextRegistry $stagingRegistry,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{draws: array<int, ValidationResult>, slots: list<array<string, mixed>>, evidence: array<string, mixed>}
     */
    public function run(MediaProcessingLog $log, array $input): array
    {
        $input['evidence_version'] = EnsembleEvidenceVersion::snapshot($input);
        $models = config('media-processing.service_structure.ensemble.models', [
            'gpt-5.6-luna', 'gpt-5.6-luna', 'gpt-6-luna', 'gpt-6-luna',
        ]);

        if (! is_array($models) || count($models) !== 4 || count(array_filter($models, 'is_string')) !== 4) {
            throw new RuntimeException('Service structure ensemble must configure exactly four model slots.');
        }

        $attempt = (string) Str::uuid();
        $base = ServiceArtifactDisk::DURABLE_PREFIX.$log->processing_id.'.ensemble.'.$attempt;
        $inputPath = $base.'.input.json';
        $rawInput = json_encode(ServiceStructureEnsembleInput::forStorage($input), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $hash = hash('sha256', $rawInput);
        $disk = Storage::disk(ServiceArtifactDisk::name());

        if (! $disk->put($inputPath, $rawInput, ServiceArtifactDisk::WRITE_OPTIONS)) {
            throw new RuntimeException('Could not bank service structure ensemble input.');
        }

        $evidence = [
            'attempt_id' => $attempt,
            'input_path' => $inputPath,
            'input_hash' => $hash,
            'source_hash' => hash('sha256', json_encode([
                $input['source'] ?? null,
                $input['oos_items'] ?? null,
                $input['validation_context'] ?? null,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            'artifact_disk' => ServiceArtifactDisk::name(),
            'models' => array_values($models),
            'evidence_version_hash' => hash('sha256', json_encode($input['evidence_version'], JSON_THROW_ON_ERROR)),
            'slots' => array_map(static fn (int $slot): array => [
                'slot' => $slot,
                'model' => $models[$slot],
                'path' => $base.'.slot-'.$slot.'.json',
            ], range(0, 3)),
            'started_at' => now()->toIso8601String(),
        ];

        $this->appendEvidence($log, $evidence);

        $outcomes = config('media-processing.service_structure.detector') === 'mock'
            ? $this->runInline($input, $evidence)
            : $this->runSubprocesses($evidence);

        $draws = [];
        $slots = [];

        foreach ($evidence['slots'] as $slot) {
            $number = $slot['slot'];
            $outcome = $outcomes[$number];
            $rawSlot = $disk->get($slot['path']);

            if (! is_string($rawSlot)) {
                throw new RuntimeException('Ensemble slot evidence was not banked.');
            }

            $slotHash = hash('sha256', $rawSlot);
            $evidence['slots'][$number]['sha256'] = $slotHash;
            $slots[] = [
                'slot' => $number,
                'model' => $slot['model'],
                'path' => $slot['path'],
                'status' => $outcome['status'],
                'sha256' => $slotHash,
            ];

            if (! in_array($outcome['status'], ['valid', 'invalid'], true)) {
                continue;
            }

            $draws[$number] = new ValidationResult(
                ServiceStructure::fromArray($outcome['validated']),
                $outcome['hard_failures'] ?? [],
                $outcome['unmatched_oos_item_ids'] ?? [],
            );
        }

        $evidence['completed_at'] = now()->toIso8601String();
        $evidence['outcomes'] = $slots;
        $this->replaceEvidence($log, $evidence);

        return ['draws' => $draws, 'slots' => $slots, 'evidence' => $evidence];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $evidence
     * @return array<int, array<string, mixed>>
     */
    private function runInline(array $input, array $evidence): array
    {
        $outcomes = [];
        $disk = Storage::disk(ServiceArtifactDisk::name());

        foreach ($evidence['slots'] as $slot) {
            $started = microtime(true);

            try {
                $draw = $this->executor->execute($input, $slot['model']);
                $outcome = [
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
                $outcome = ['status' => 'unavailable', 'error' => $exception->getMessage(), 'usage' => 'unknown'];
            }

            $outcome['elapsed_seconds'] = microtime(true) - $started;
            $outcome['input_hash'] = $evidence['input_hash'];
            $outcome['model'] = $slot['model'];
            $outcome['artifact_disk'] = $evidence['artifact_disk'];
            $disk->put($slot['path'], json_encode($outcome, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), ServiceArtifactDisk::WRITE_OPTIONS);
            $outcomes[$slot['slot']] = $outcome;
        }

        return $outcomes;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<int, array<string, mixed>>
     */
    private function runSubprocesses(array $evidence): array
    {
        $context = $this->stagingRegistry->activeContext();
        $encodedContext = $context === null ? null : base64_encode(json_encode($context->toArray(), JSON_THROW_ON_ERROR));
        $processes = [];
        $exits = [];
        $started = microtime(true);
        $deadline = min(300, (int) config('media-processing.service_structure.ensemble.draw_timeout_seconds', 300));

        try {
            foreach ($evidence['slots'] as $slot) {
                $process = new Process($this->subprocessCommand($slot, $evidence, $encodedContext), base_path());
                $process->setTimeout($deadline);
                $process->start();
                $processes[$slot['slot']] = $process;
            }

            while ($processes !== []) {
                foreach ($processes as $slot => $process) {
                    if (! $process->isRunning()) {
                        $exits[$slot] = [
                            'exit_code' => $process->getExitCode(),
                            'error' => mb_substr($process->getErrorOutput(), 0, 1000),
                            'output' => mb_substr($process->getOutput(), 0, 1000),
                        ];
                        unset($processes[$slot]);
                    }
                }

                if ($processes === []) {
                    break;
                }

                if (microtime(true) - $started >= $deadline) {
                    break;
                }

                usleep(100_000);
            }
        } finally {
            foreach ($processes as $process) {
                $process->stop(0);
            }
        }

        $outcomes = [];
        $disk = Storage::disk(ServiceArtifactDisk::name());

        foreach ($evidence['slots'] as $slot) {
            $raw = $disk->get($slot['path']);
            $outcome = is_string($raw) ? json_decode($raw, true) : null;

            if (! is_array($outcome) || ($outcome['input_hash'] ?? null) !== $evidence['input_hash']
                || ($outcome['artifact_disk'] ?? null) !== $evidence['artifact_disk']
                || ($outcome['model'] ?? null) !== $slot['model']) {
                $outcome = [
                    'status' => 'interrupted',
                    'usage' => 'unknown',
                    'input_hash' => $evidence['input_hash'],
                    'model' => $slot['model'],
                    'artifact_disk' => $evidence['artifact_disk'],
                    ...($exits[$slot['slot']] ?? []),
                ];
                $disk->put($slot['path'], json_encode($outcome, JSON_THROW_ON_ERROR), ServiceArtifactDisk::WRITE_OPTIONS);
            }

            $outcomes[$slot['slot']] = $outcome;
        }

        return $outcomes;
    }

    /**
     * @param  array<string, mixed>  $slot
     * @param  array<string, mixed>  $evidence
     * @return list<string>
     */
    protected function subprocessCommand(array $slot, array $evidence, ?string $encodedContext): array
    {
        return [
            PHP_BINARY,
            base_path('artisan'),
            'service:structure-draw',
            $evidence['input_path'],
            $slot['path'],
            $slot['model'],
            $evidence['input_hash'],
            $encodedContext ?? '',
            $evidence['artifact_disk'],
        ];
    }

    /** @param  array<string, mixed>  $evidence */
    private function appendEvidence(MediaProcessingLog $log, array $evidence): void
    {
        DB::transaction(function () use ($log, $evidence): void {
            $fresh = MediaProcessingLog::query()->lockForUpdate()->findOrFail($log->id);
            $metadata = $fresh->processing_metadata?->toArray() ?? [];
            $bank = is_array($metadata['service_structure_ensemble'] ?? null) ? $metadata['service_structure_ensemble'] : [];
            $bank[] = $evidence;
            $metadata['service_structure_ensemble'] = $bank;
            $artifacts = is_array($metadata['service_artifacts'] ?? null) ? $metadata['service_artifacts'] : [];
            $paths = [$evidence['input_path']];

            foreach ($evidence['slots'] as $slot) {
                $paths[] = $slot['path'];
            }

            foreach ($paths as $path) {
                $artifacts[] = [
                    'kind' => self::ARTIFACT_KIND,
                    'disk' => $evidence['artifact_disk'],
                    'path' => $path,
                    'attempt_id' => $evidence['attempt_id'],
                ];
            }

            $metadata['service_artifacts'] = $artifacts;
            $fresh->forceFill(['processing_metadata' => $metadata])->save();
        });
    }

    /** @param  array<string, mixed>  $evidence */
    private function replaceEvidence(MediaProcessingLog $log, array $evidence): void
    {
        DB::transaction(function () use ($log, $evidence): void {
            $fresh = MediaProcessingLog::query()->lockForUpdate()->findOrFail($log->id);
            $metadata = $fresh->processing_metadata?->toArray() ?? [];
            $bank = is_array($metadata['service_structure_ensemble'] ?? null) ? $metadata['service_structure_ensemble'] : [];

            foreach ($bank as $index => $entry) {
                if (($entry['attempt_id'] ?? null) === $evidence['attempt_id']) {
                    $bank[$index] = $evidence;
                }
            }

            $metadata['service_structure_ensemble'] = $bank;
            $fresh->forceFill(['processing_metadata' => $metadata])->save();
        });
    }

    /** @param  array<string, mixed>  $composition */
    public function recordComposition(MediaProcessingLog $log, string $attemptId, array $composition): void
    {
        DB::transaction(function () use ($log, $attemptId, $composition): void {
            $fresh = MediaProcessingLog::query()->lockForUpdate()->findOrFail($log->id);
            $metadata = $fresh->processing_metadata?->toArray() ?? [];
            $bank = is_array($metadata['service_structure_ensemble'] ?? null) ? $metadata['service_structure_ensemble'] : [];

            foreach ($bank as $index => $entry) {
                if (($entry['attempt_id'] ?? null) === $attemptId) {
                    $bank[$index]['composition'] = $composition;
                }
            }

            $metadata['service_structure_ensemble'] = $bank;
            $fresh->forceFill(['processing_metadata' => $metadata])->save();
        });
    }
}
