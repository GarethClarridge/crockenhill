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

    /** Outcomes where the slot gave no answer, as opposed to an answer that failed validation. */
    private const LOST_DRAW_STATUSES = ['unavailable', 'interrupted'];

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

        $outcomes = $this->execute($input, $evidence);
        [$outcomes, $evidence] = $this->retryFailedSlots($log, $input, $evidence, $outcomes);

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
    private function execute(array $input, array $evidence): array
    {
        return config('media-processing.service_structure.detector') === 'mock'
            ? $this->runInline($input, $evidence)
            : $this->runSubprocesses($evidence);
    }

    /**
     * Draw each slot that never answered once more, with the same model and input, before anything is composed.
     *
     * A draw lost to the provider or the process (canary 15: run 1005 lost all four to a transient
     * cURL error) is no opinion at all, and three voters split two to one where four decide three to
     * one. An `invalid` draw is an opinion and is never drawn again: regenerating one until it
     * passed rewrote unrelated content in canary 8 (run 949). The retry is banked in its own file,
     * listed with the run's artifacts before it is drawn, and the slot then points at it whatever it
     * answered, so replay can revalidate a retry that failed validation exactly as it does a first
     * draw; the first attempt stays on record as `first_attempt`.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $evidence
     * @param  array<int, array<string, mixed>>  $outcomes
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, mixed>}
     */
    private function retryFailedSlots(MediaProcessingLog $log, array $input, array $evidence, array $outcomes): array
    {
        $failed = array_values(array_filter(
            $evidence['slots'],
            static fn (array $slot): bool => in_array($outcomes[$slot['slot']]['status'] ?? null, self::LOST_DRAW_STATUSES, true),
        ));

        if ($failed === []) {
            return [$outcomes, $evidence];
        }

        $retries = array_map(static fn (array $slot): array => [
            ...$slot,
            'path' => preg_replace('/\.json$/', '.retry-1.json', $slot['path']),
        ], $failed);
        $this->registerArtifacts($log, $evidence, array_column($retries, 'path'));
        $retryOutcomes = $this->execute($input, [...$evidence, 'slots' => $retries]);

        foreach ($retries as $retry) {
            $number = $retry['slot'];
            $evidence['slots'][$number] = [
                ...$retry,
                'first_attempt' => [
                    'path' => $evidence['slots'][$number]['path'],
                    'status' => $outcomes[$number]['status'] ?? 'unknown',
                ],
            ];
            $outcomes[$number] = $retryOutcomes[$number];
        }

        return [$outcomes, $evidence];
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
            // A child killed at the deadline banks nothing, and the durable disk throws on a missing file.
            $raw = $disk->exists($slot['path']) ? $disk->get($slot['path']) : null;
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
            $fresh->forceFill(['processing_metadata' => $metadata])->save();
        });

        $this->registerArtifacts($log, $evidence, [$evidence['input_path'], ...array_column($evidence['slots'], 'path')]);
    }

    /**
     * List evidence files in the run's service artifacts, so moving the run's artifacts moves the whole bundle.
     *
     * @param  array<string, mixed>  $evidence
     * @param  list<string>  $paths
     */
    private function registerArtifacts(MediaProcessingLog $log, array $evidence, array $paths): void
    {
        DB::transaction(function () use ($log, $evidence, $paths): void {
            $fresh = MediaProcessingLog::query()->lockForUpdate()->findOrFail($log->id);
            $metadata = $fresh->processing_metadata?->toArray() ?? [];
            $artifacts = is_array($metadata['service_artifacts'] ?? null) ? $metadata['service_artifacts'] : [];

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
