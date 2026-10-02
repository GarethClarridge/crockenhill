<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ChurchServiceTranscript;
use App\Enums\ChurchServiceItemSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Services\Media\Audio\AudioTimeline;
use App\Support\ServiceArtifactDisk;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use UnexpectedValueException;

/**
 * The one snapshot every ensemble draw reads: transcript, audio timeline, planned items,
 * validation policy and RMS log, with the hashes of the artifacts they came from.
 *
 * Shared by the detection job and the paid evaluation, so an evaluated draw is sent exactly
 * what a production draw would be.
 */
class ServiceStructureEnsembleInput
{
    /**
     * @return array{input: array<string, mixed>, transcript: ChurchServiceTranscript, context: ValidationContext}
     */
    public function build(MediaProcessingLog $log, ?ChurchService $churchService): array
    {
        $transcriptPath = $log->serviceTranscriptPath();
        $timelinePath = $log->audio_timeline_path;

        if (! is_string($transcriptPath)) {
            throw new RuntimeException('No full-service transcript recorded for this run; TranscribeFullService must run first.');
        }

        if (! is_string($timelinePath)) {
            throw new RuntimeException('No audio timeline recorded for this run; ClassifyServiceAudio must run first.');
        }

        $transcriptRaw = Storage::disk(ServiceArtifactDisk::for($transcriptPath))->get($transcriptPath);
        $timelineRaw = Storage::disk(ServiceArtifactDisk::for($timelinePath))->get($timelinePath);

        if (! is_string($transcriptRaw) || ! is_string($timelineRaw)) {
            throw new RuntimeException('Ensemble detection source artifacts are missing.');
        }

        $transcript = ChurchServiceTranscript::fromArray(json_decode($transcriptRaw, true, flags: JSON_THROW_ON_ERROR));

        try {
            $timeline = AudioTimeline::fromJson($timelineRaw);
        } catch (UnexpectedValueException $exception) {
            throw new RuntimeException("Audio timeline artifact is unreadable ({$timelinePath}): ".$exception->getMessage(), previous: $exception);
        }

        if ($transcript->isEmpty()) {
            throw new RuntimeException('Stored full-service transcript contains no cues.');
        }

        $oosItems = $this->oosItems($churchService);
        $context = ValidationContext::for(
            $transcript,
            $oosItems,
            ValidationContext::recordingOmitsSongs($log->processing_metadata),
        );
        $rmsPath = $log->rms_log_path;
        $rms = is_string($rmsPath) && Storage::disk(ServiceArtifactDisk::for($rmsPath))->exists($rmsPath)
            ? Storage::disk(ServiceArtifactDisk::for($rmsPath))->get($rmsPath)
            : null;

        return [
            'input' => [
                'processing_id' => $log->processing_id,
                'transcript' => $transcript->toArray(),
                'audio_timeline' => $timeline->toArray(),
                'oos_items' => $this->oosItemPayloads($oosItems),
                'validation_context' => ServiceStructureDrawExecutor::contextSnapshot($context),
                'rms_log' => $rms,
                'source' => [
                    'church_service_id' => $log->church_service_id,
                    'transcript_path' => $transcriptPath,
                    'audio_timeline_path' => $timelinePath,
                    'rms_log_path' => $rmsPath,
                    'transcript_hash' => hash('sha256', $transcriptRaw),
                    'audio_timeline_hash' => hash('sha256', $timelineRaw),
                    'rms_log_hash' => is_string($rms) ? hash('sha256', $rms) : null,
                ],
            ],
            'transcript' => $transcript,
            'context' => $context,
        ];
    }

    /**
     * The input as it is banked: the RMS log is named by its path and hash in `source`, not
     * copied. It is almost all of a snapshot's size (an hour's log is 11–16 MB against about
     * 300 KB for everything else), it is already a retained service artifact, and every
     * attempt on a run would otherwise bank another copy of it.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function forStorage(array $input): array
    {
        unset($input['rms_log']);

        return $input;
    }

    /**
     * A banked input with its RMS log read back from the artifact its hash names. An input
     * banked before {@see self::forStorage()} carries its log inside and is returned as it is.
     * A log that is missing or no longer matches its hash refuses: draws refined without it
     * would differ from the ones banked.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function hydrate(array $input): array
    {
        if (array_key_exists('rms_log', $input)) {
            return $input;
        }

        $path = $input['source']['rms_log_path'] ?? null;
        $hash = $input['source']['rms_log_hash'] ?? null;

        if ($hash === null) {
            return [...$input, 'rms_log' => null];
        }

        $rms = is_string($path) ? Storage::disk(ServiceArtifactDisk::for($path))->get($path) : null;

        if (! is_string($rms) || hash('sha256', $rms) !== $hash) {
            throw new RuntimeException('The RMS log an ensemble input names is missing or has changed.');
        }

        return [...$input, 'rms_log' => $rms];
    }

    /**
     * The planned service: only items a source other than the recording attests.
     *
     * An item the pipeline's own projection wrote is this detector's earlier answer, and
     * reading it back as the plan would make each round follow the last (run 949's four
     * church slots stayed talks across two canaries). A service whose items are all
     * self-written is detected from its transcript alone. Provenance is read from the
     * evidence, not the `source` column, which keeps the first writer after a merge.
     *
     * @return list<ChurchServiceItem>
     */
    public function oosItems(?ChurchService $churchService): array
    {
        if (! $churchService instanceof ChurchService) {
            return [];
        }

        return array_values($churchService->items()
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->filter(fn (ChurchServiceItem $item): bool => collect($item->provenanceSources())
                ->contains(fn (ChurchServiceItemSource $source): bool => ! $source->isDetected()))
            ->all());
    }

    /**
     * @param  list<ChurchServiceItem>  $items
     * @return list<array{id: int, position: int, type: string, title: ?string, song_id: ?int}>
     */
    public function oosItemPayloads(array $items): array
    {
        return array_map(
            static fn (ChurchServiceItem $item): array => [
                'id' => (int) $item->id,
                'position' => (int) $item->position,
                'type' => $item->semanticSectionType()->value,
                'title' => $item->title,
                'song_id' => $item->song_id === null ? null : (int) $item->song_id,
            ],
            $items
        );
    }
}
