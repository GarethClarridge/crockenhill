<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use App\Contracts\ServiceTranscriptionInterface;
use App\Data\ChurchServiceTranscript;
use App\Exceptions\NonRetryableTranscriptionException;
use App\Exceptions\TranscriptionException;
use App\Services\Processing\SermonProcessingLogger;
use Exception;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\TransporterException;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Audio\TranscriptionResponse;

/**
 * Whole-recording timestamped transcription via the OpenAI Whisper API.
 *
 * Always transcodes the recording to mono low-bitrate audio first (the input
 * is usually a multi-gigabyte video), then splits it into overlapping chunks
 * when it still exceeds the API's 25 MB upload limit, re-offsetting each
 * chunk's cue times back into whole-recording seconds.
 */
class OpenAiServiceTranscriptionService implements ServiceTranscriptionInterface
{
    public function __construct(
        private readonly SermonProcessingLogger $logger,
        private readonly AudioChunkingService $chunkingService,
    ) {}

    public function transcribeService(string $audioOrVideoPath, string $processingId): ChurchServiceTranscript
    {
        if (empty(config('media-processing.transcription.openai_api_key'))) {
            throw new NonRetryableTranscriptionException('OpenAI API key not configured for service transcription');
        }

        if (! file_exists($audioOrVideoPath)) {
            throw new TranscriptionException("Recording not found for service transcription: {$audioOrVideoPath}");
        }

        $this->logger->logProcessingStep(
            $processingId,
            'service_transcription',
            'started',
            ['file_path' => $audioOrVideoPath]
        );

        $audioPath = $this->chunkingService->compressAudioForTranscription($audioOrVideoPath, $processingId);

        try {
            $transcript = filesize($audioPath) <= $this->maxUploadBytes()
                ? $this->transcribeWhole($audioPath, $processingId)
                : $this->transcribeInChunks($audioPath, $processingId);

            $this->logger->logProcessingStep(
                $processingId,
                'service_transcription',
                'completed',
                [
                    'cue_count' => count($transcript->cues),
                    'duration' => $transcript->duration,
                ]
            );

            return $transcript;
        } finally {
            if (file_exists($audioPath)) {
                unlink($audioPath);
            }
        }
    }

    private function transcribeWhole(string $audioPath, string $processingId): ChurchServiceTranscript
    {
        $response = $this->requestVerboseTranscription($audioPath, $processingId);

        return ChurchServiceTranscript::fromCues(
            $this->cuesFromResponse($response, 0.0),
            (float) ($response->duration ?? 0.0),
            ChurchServiceTranscript::SOURCE_WHISPER_API,
        );
    }

    private function transcribeInChunks(string $audioPath, string $processingId): ChurchServiceTranscript
    {
        $duration = $this->chunkingService->getAudioDuration($audioPath);
        $chunkPaths = $this->chunkingService->createAudioChunks($audioPath, $processingId, $duration);

        if ($chunkPaths === []) {
            throw new TranscriptionException('Audio chunking produced no chunks for service transcription');
        }

        // The previous chunk runs one overlap window past its nominal boundary
        // and the next chunk starts one overlap window before it, so the
        // duplicated audio at every joint is two overlap windows long.
        $duplicatedWindowSeconds = 2.0 * (float) $this->chunkingService->getChunkOverlapSeconds();
        $cues = [];

        try {
            foreach ($chunkPaths as $index => $chunkPath) {
                $chunkStart = $this->chunkStartSeconds($index);
                $response = $this->requestVerboseTranscription($chunkPath, $processingId);

                foreach ($this->cuesFromResponse($response, $chunkStart) as $cue) {
                    // Cues ending inside the duplicated window were already
                    // covered in full by the previous chunk. A cue that starts
                    // inside it but runs past the previous chunk's true end is
                    // kept whole: better to repeat a few overlapped words than
                    // to lose the first speech after every chunk joint.
                    if ($index > 0 && ($cue['end'] - $chunkStart) <= $duplicatedWindowSeconds) {
                        continue;
                    }

                    $cues[] = $cue;
                }
            }
        } finally {
            $this->chunkingService->cleanupChunkFiles($chunkPaths, $processingId);
        }

        return ChurchServiceTranscript::fromCues($cues, $duration, ChurchServiceTranscript::SOURCE_WHISPER_API);
    }

    /**
     * Where a chunk starts in whole-recording seconds. Mirrors the walk in
     * AudioChunkingService::createAudioChunks(): every chunk after the first
     * begins one overlap-window before its nominal boundary.
     */
    private function chunkStartSeconds(int $index): float
    {
        if ($index === 0) {
            return 0.0;
        }

        $chunkDurationSeconds = $this->chunkingService->getChunkDurationMinutes() * 60;
        $overlapSeconds = $this->chunkingService->getChunkOverlapSeconds();

        return (float) ($index * ($chunkDurationSeconds - $overlapSeconds) - $overlapSeconds);
    }

    private function requestVerboseTranscription(string $audioPath, string $processingId): TranscriptionResponse
    {
        $apiStartTime = microtime(true);

        try {
            $response = OpenAI::audio()->transcribe([
                'file' => fopen($audioPath, 'r'),
                'model' => (string) config('media-processing.service_structure.transcription_model', 'whisper-1'),
                'response_format' => 'verbose_json',
                'language' => 'en',
                'prompt' => (string) config('media-processing.transcription.prompts.full_service'),
            ]);

            $this->logger->logApiCall(
                $processingId,
                'OpenAI',
                'audio/transcriptions',
                microtime(true) - $apiStartTime,
                200,
                null,
                ['response_format' => 'verbose_json', 'file' => basename($audioPath)]
            );

            return $response;
        } catch (ErrorException|TransporterException $e) {
            $this->logger->logApiCall(
                $processingId,
                'OpenAI',
                'audio/transcriptions',
                microtime(true) - $apiStartTime,
                (int) $e->getCode(),
                $e->getMessage(),
                ['response_format' => 'verbose_json', 'file' => basename($audioPath)]
            );

            throw new TranscriptionException('Service transcription failed: '.$e->getMessage(), 0, $e);
        } catch (Exception $e) {
            throw new TranscriptionException('Service transcription failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @return list<array{start: float, end: float, text: string}>
     */
    private function cuesFromResponse(TranscriptionResponse $response, float $offsetSeconds): array
    {
        $cues = [];

        foreach ($response->segments as $segment) {
            $cues[] = [
                'start' => $offsetSeconds + (float) $segment->start,
                'end' => $offsetSeconds + (float) $segment->end,
                'text' => trim($segment->text),
            ];
        }

        if ($cues === []) {
            throw new TranscriptionException('Whisper verbose_json response contained no timestamped segments');
        }

        return $cues;
    }

    private function maxUploadBytes(): int
    {
        return (int) config('media-processing.transcription.max_file_size', 25 * 1024 * 1024);
    }
}
