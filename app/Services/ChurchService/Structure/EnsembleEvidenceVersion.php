<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ChurchServiceTranscript;
use App\Services\Media\Audio\AudioTimeline;
use App\Services\Scripture\ScriptureReferenceResolver;
use App\Services\Song\SongTitleHygiene;
use RuntimeException;

/** Binds a four-draw input to the prompt, schema and deterministic code that interpreted it. */
class EnsembleEvidenceVersion
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function snapshot(array $input): array
    {
        $transcript = ChurchServiceTranscript::fromArray($input['transcript'] ?? null);
        $timelinePayload = $input['audio_timeline'] ?? null;
        $oosItems = $input['oos_items'] ?? null;

        if ($transcript->isEmpty() || ! is_array($timelinePayload) || ! is_array($oosItems)) {
            throw new RuntimeException('Cannot version incomplete ensemble evidence.');
        }

        $detector = app(OpenAiServiceStructureService::class);
        $prompt = $detector->buildPrompt($transcript, $oosItems, [], AudioTimeline::fromArray($timelinePayload));
        $schema = $detector->responseFormat();
        $sourceHashes = [];

        foreach ([
            OpenAiServiceStructureService::class,
            ServiceStructureDrawExecutor::class,
            ServiceStructureValidator::class,
            SilenceSnapService::class,
            SoundStage::class,
            ServiceStructureEnsembleComposer::class,
            ServiceStructureEnsembleRulingApplier::class,
            ScriptureReferenceResolver::class,
            SongTitleHygiene::class,
        ] as $class) {
            $path = (new \ReflectionClass($class))->getFileName();
            $hash = is_string($path) ? hash_file('sha256', $path) : false;

            if (! is_string($hash)) {
                throw new RuntimeException('Could not fingerprint ensemble detector and rule code.');
            }

            $sourceHashes[$class] = $hash;
        }

        return [
            'prompt' => $prompt,
            'response_schema' => $schema,
            'code_hashes' => $sourceHashes,
            'rule_version' => 'ensemble-v1-er1',
            'reasoning_effort' => (string) config('media-processing.service_structure.reasoning_effort', 'medium'),
            'requested_service_tier' => config('openai.service_tier'),
        ];
    }
}
