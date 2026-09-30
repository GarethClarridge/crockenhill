<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Models\MediaProcessingLog;
use App\Support\ServiceArtifactDisk;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Re-evaluates immutable draw evidence with current deterministic rules and no detector call. */
class ServiceStructureEnsembleReplay
{
    public function __construct(
        private readonly ServiceStructureDrawExecutor $executor,
        private readonly CutAwareEnsembleComposer $composer,
        private readonly ServiceStructureValidator $validator,
        private readonly ServiceStructureEnsembleRulingApplier $rulings,
    ) {}

    /**
     * @param  array<string, mixed>  $evidence
     * @param  list<array<string, mixed>>  $rulings
     * @param  MediaProcessingLog|null  $log  The run the draws were made for; without it the cut
     *                                        cannot be planned, so no filler question is dropped
     * @return array<string, mixed>
     */
    public function replay(array $evidence, array $rulings = [], ?MediaProcessingLog $log = null): array
    {
        $input = $this->snapshot($evidence);
        $inputHash = $evidence['input_hash'];
        $diskName = $evidence['artifact_disk'];
        $writtenTo = self::originDisk($evidence);
        $disk = Storage::disk($diskName);
        $slots = $evidence['slots'] ?? null;

        if (! is_array($slots) || count($slots) !== 4) {
            throw new RuntimeException('Ensemble replay requires four immutable slot records.');
        }

        $draws = [];
        $outcomes = [];

        foreach ($slots as $slot) {
            $number = $slot['slot'] ?? null;
            $path = $slot['path'] ?? null;
            $model = $slot['model'] ?? null;
            $slotHash = $slot['sha256'] ?? null;

            if (! is_int($number) || ! in_array($number, [0, 1, 2, 3], true)
                || isset($outcomes[$number]) || ! is_string($path)
                || ! str_starts_with($path, ServiceArtifactDisk::DURABLE_PREFIX)
                || ! is_string($model) || ! is_string($slotHash)) {
                throw new RuntimeException('Ensemble replay slot identity is invalid or duplicated.');
            }

            $raw = $disk->get($path);

            if (! is_string($raw) || hash('sha256', $raw) !== $slotHash) {
                throw new RuntimeException('Ensemble replay slot evidence is missing or changed.');
            }

            $record = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($record) || ($record['input_hash'] ?? null) !== $inputHash
                || ($record['model'] ?? null) !== $model
                || ($record['artifact_disk'] ?? null) !== $writtenTo) {
                throw new RuntimeException('Ensemble replay slot evidence differs from its manifest.');
            }

            $status = $record['status'] ?? null;

            if (in_array($status, ['valid', 'invalid'], true)) {
                if (! is_array($record['raw'] ?? null)) {
                    throw new RuntimeException('Ensemble replay slot has no parsed raw structure.');
                }

                $replayed = $this->executor->refineAndValidate($input, ServiceStructure::fromArray($record['raw']));
                $draws[$number] = $replayed['validation'];
                $outcomes[$number] = [
                    'status_before' => $status,
                    'status_after' => $replayed['validation']->passed() ? 'valid' : 'invalid',
                    'failure_codes_after' => $replayed['validation']->failureCodes(),
                ];

                continue;
            }

            if (! in_array($status, ['unavailable', 'interrupted'], true)) {
                throw new RuntimeException('Ensemble replay slot has an unknown outcome.');
            }

            $outcomes[$number] = ['status_before' => $status, 'status_after' => $status];
        }

        ksort($outcomes);
        $composition = $this->composer->compose($draws, ChurchServiceTranscript::fromArray($input['transcript'] ?? null), $log);
        $contextPayload = $input['validation_context'] ?? null;

        if (! is_array($contextPayload)) {
            throw new RuntimeException('Ensemble replay input is missing validation context.');
        }

        $proposal = [
            'attempt_id' => $evidence['attempt_id'] ?? null,
            'input_hash' => $inputHash,
            'source_hash' => hash('sha256', json_encode([
                $input['source'] ?? null,
                $input['oos_items'] ?? null,
                $input['validation_context'] ?? null,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            'rule_version' => 'ensemble-v1-er1',
            'slots' => $outcomes,
            'structure' => $composition->structure->toArray(),
            'degraded' => $composition->degraded,
            'disputes' => $composition->disputes,
            'provenance' => $composition->provenance,
            'before' => $evidence['composition'] ?? null,
        ];

        $corrected = $this->rulings->apply($proposal, $rulings);
        $validated = $composition->refused
            ? null
            : $this->validator->validate(
                ServiceStructure::fromArray($corrected['structure']),
                ServiceStructureDrawExecutor::contextFromSnapshot($contextPayload),
            );

        return [
            ...$corrected,
            'validation_passed' => $validated?->passed() ?? false,
            'failure_codes' => $validated?->failureCodes() ?? ['insufficient_ensemble_votes'],
        ];
    }

    /**
     * The disk a bundle's files were written to, which their hashed bytes record: the current
     * disk unless the bundle has since been moved.
     *
     * @param  array<string, mixed>  $evidence
     */
    public static function originDisk(array $evidence): mixed
    {
        return $evidence['origin_disk'] ?? $evidence['artifact_disk'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    public function snapshot(array $evidence): array
    {
        $diskName = $evidence['artifact_disk'] ?? null;
        $inputPath = $evidence['input_path'] ?? null;
        $inputHash = $evidence['input_hash'] ?? null;

        if (! is_string($diskName) || $diskName !== ServiceArtifactDisk::name()
            || ! is_string($inputPath) || ! str_starts_with($inputPath, ServiceArtifactDisk::DURABLE_PREFIX)
            || ! is_string($inputHash)) {
            throw new RuntimeException('Ensemble replay manifest has an invalid private artifact identity.');
        }

        $disk = Storage::disk($diskName);
        $rawInput = $disk->get($inputPath);

        if (! is_string($rawInput) || hash('sha256', $rawInput) !== $inputHash) {
            throw new RuntimeException('Ensemble replay input is missing or differs from its manifest.');
        }

        $input = json_decode($rawInput, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($input)) {
            throw new RuntimeException('Ensemble replay input must be an object.');
        }

        return $input;
    }
}
