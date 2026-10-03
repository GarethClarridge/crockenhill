<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Contracts\ServiceStructureInterface;
use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Enums\ServiceSectionType;
use App\Services\Media\Audio\AudioTimeline;
use RuntimeException;

/** The shared detector/refinement/validation path for a single immutable vote. */
class ServiceStructureDrawExecutor
{
    public function __construct(
        private readonly ServiceStructureInterface $detector,
        private readonly SilenceSnapService $snapService,
        private readonly SoundStage $soundStage,
        private readonly ServiceStructureValidator $validator,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{raw: ServiceStructure, raw_response: string|null, refined: ServiceStructure, validation: ValidationResult, usage: array<string, int>|null, service_tier: string|null}
     */
    public function execute(array $input, string $model): array
    {
        if (($input['evidence_version'] ?? null) !== EnsembleEvidenceVersion::snapshot($input)) {
            throw new RuntimeException('Ensemble prompt, schema, policy or code changed after the input was banked.');
        }

        $transcript = ChurchServiceTranscript::fromArray($input['transcript'] ?? null);
        $timelinePayload = $input['audio_timeline'] ?? null;

        if ($transcript->isEmpty() || ! is_array($timelinePayload)) {
            throw new RuntimeException('Ensemble draw snapshot is missing transcript or audio timeline.');
        }

        $timeline = AudioTimeline::fromArray($timelinePayload);
        $oosItems = $input['oos_items'] ?? null;
        $contextPayload = $input['validation_context'] ?? null;

        if (! is_array($oosItems) || ! is_array($contextPayload)) {
            throw new RuntimeException('Ensemble draw snapshot is missing service policy.');
        }

        $processingId = $input['processing_id'] ?? null;
        $raw = $this->detector->detect(
            $transcript,
            $oosItems,
            is_string($processingId) ? $processingId : null,
            [],
            $timeline,
            $model,
        );
        $telemetry = app(ServiceStructureEvaluationTelemetry::class);
        $usage = $telemetry->take();
        $serviceTier = $telemetry->takeServiceTier();
        $rawResponse = $telemetry->takeRawResponse();

        $result = $this->refineAndValidate($input, $raw);

        return [
            'raw' => $raw,
            'raw_response' => $rawResponse,
            'refined' => $result['refined'],
            'validation' => $result['validation'],
            'usage' => $usage,
            'service_tier' => $serviceTier,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{refined: ServiceStructure, validation: ValidationResult}
     */
    public function refineAndValidate(array $input, ServiceStructure $raw): array
    {
        $transcript = ChurchServiceTranscript::fromArray($input['transcript'] ?? null);
        $timelinePayload = $input['audio_timeline'] ?? null;

        if ($transcript->isEmpty() || ! is_array($timelinePayload)) {
            throw new RuntimeException('Ensemble replay snapshot is missing transcript or audio timeline.');
        }

        $timeline = AudioTimeline::fromArray($timelinePayload);
        $contextPayload = $input['validation_context'] ?? null;

        if (! is_array($contextPayload)) {
            throw new RuntimeException('Ensemble replay snapshot is missing service policy.');
        }

        $context = self::contextFromSnapshot($contextPayload);

        $refined = app(TranscriptCueBoundaries::class)->apply($raw, $transcript);
        $rms = $input['rms_log'] ?? null;

        if (is_string($rms) && $rms !== '') {
            $refined = $this->soundStage->apply(
                $this->snapService->snap($refined, $rms, $transcript),
                $rms,
                $transcript,
                $context->recordingOmitsSongs,
                $timeline,
            );
        }

        $refined = app(TranscriptCueBoundaries::class)->finish($refined, $transcript)['structure'];

        return [
            'refined' => $refined,
            'validation' => $this->validator->validate($refined, $context),
        ];
    }

    /** @return array<string, mixed> */
    public static function contextSnapshot(ValidationContext $context): array
    {
        return [
            'recording_duration' => $context->recordingDuration,
            'speech_duration' => $context->speechDuration,
            'oos_item_types' => array_map(static fn (ServiceSectionType $type): string => $type->value, $context->oosItemTypes),
            'cues' => $context->cues,
            'oos_item_positions' => $context->oosItemPositions,
            'oos_item_raw_types' => $context->oosItemRawTypes,
            'recording_omits_songs' => $context->recordingOmitsSongs,
        ];
    }

    /** @param  array<string, mixed>  $payload */
    public static function contextFromSnapshot(array $payload): ValidationContext
    {
        $types = [];

        foreach ($payload['oos_item_types'] ?? [] as $id => $type) {
            if (! is_string($type)) {
                throw new RuntimeException('Ensemble draw snapshot has invalid OoS item type.');
            }

            $types[(int) $id] = ServiceSectionType::from($type);
        }

        return new ValidationContext(
            recordingDuration: (float) ($payload['recording_duration'] ?? 0),
            speechDuration: (float) ($payload['speech_duration'] ?? 0),
            oosItemTypes: $types,
            cues: $payload['cues'] ?? [],
            oosItemPositions: $payload['oos_item_positions'] ?? [],
            oosItemRawTypes: $payload['oos_item_raw_types'] ?? [],
            recordingOmitsSongs: (bool) ($payload['recording_omits_songs'] ?? false),
        );
    }
}
