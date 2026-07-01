<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use App\Contracts\ServiceTranscriptionInterface;
use App\Data\ChurchServiceTranscript;
use App\Exceptions\TranscriptionException;
use App\Services\Processing\SermonProcessingLogger;
use Exception;
use Illuminate\Support\Facades\Http;

/**
 * Whole-recording timestamped transcription via a locally running Whisper
 * server (OpenAI-compatible /v1/audio/transcriptions endpoint), requesting
 * segment-level timestamps with verbose_json.
 */
class LocalWhisperServiceTranscriptionService implements ServiceTranscriptionInterface
{
    private const TRANSCRIPTION_PROMPT = 'The following is a full church service at Crockenhill Baptist Church, '
        .'in the British conservative evangelical tradition: welcome, hymns and songs, prayers, Bible readings, '
        .'notices and a sermon.';

    public function __construct(
        private readonly SermonProcessingLogger $logger,
        private readonly AudioChunkingService $chunkingService,
    ) {}

    public function transcribeService(string $audioOrVideoPath, string $processingId): ChurchServiceTranscript
    {
        if (! file_exists($audioOrVideoPath)) {
            throw new TranscriptionException("Recording not found for service transcription: {$audioOrVideoPath}");
        }

        $this->logger->logProcessingStep(
            $processingId,
            'local_whisper_service_transcription',
            'started',
            ['file_path' => $audioOrVideoPath]
        );

        // A local server has no upload limit, but mono low-bitrate audio keeps
        // the request small and the transcription fast for multi-GB videos.
        $audioPath = $this->chunkingService->compressAudioForTranscription($audioOrVideoPath, $processingId);

        try {
            $transcript = $this->requestVerboseTranscription($audioPath, $processingId);

            $this->logger->logProcessingStep(
                $processingId,
                'local_whisper_service_transcription',
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

    private function requestVerboseTranscription(string $audioPath, string $processingId): ChurchServiceTranscript
    {
        $baseUrl = (string) config('media-processing.transcription.local_whisper_url');
        $transcriptionPath = (string) config('media-processing.transcription.local_whisper_transcription_path', '/v1/audio/transcriptions');
        $endpoint = rtrim($baseUrl, '/').'/'.ltrim($transcriptionPath, '/');

        $apiStartTime = microtime(true);
        $fileHandle = fopen($audioPath, 'r');

        if ($fileHandle === false) {
            throw new TranscriptionException("Could not open audio file for reading: {$audioPath}");
        }

        try {
            $response = Http::timeout((int) config('media-processing.transcription.local_whisper_timeout', 1800))
                ->attach('file', $fileHandle, basename($audioPath))
                ->post($endpoint, [
                    'model' => (string) config('media-processing.transcription.local_whisper_model', 'small'),
                    'language' => 'en',
                    'response_format' => 'verbose_json',
                    'prompt' => self::TRANSCRIPTION_PROMPT,
                ]);
        } catch (Exception $e) {
            $this->logger->logApiCall(
                $processingId,
                'LocalWhisper',
                'audio/transcriptions',
                microtime(true) - $apiStartTime,
                0,
                $e->getMessage(),
                ['error_type' => 'connection']
            );

            throw new TranscriptionException("Local Whisper connection failed: {$e->getMessage()}", 0, $e);
        } finally {
            if (is_resource($fileHandle)) {
                fclose($fileHandle);
            }
        }

        if ($response->failed()) {
            $this->logger->logApiCall(
                $processingId,
                'LocalWhisper',
                'audio/transcriptions',
                microtime(true) - $apiStartTime,
                $response->status(),
                $response->body(),
                []
            );

            throw new TranscriptionException(
                "Local Whisper service transcription failed: HTTP {$response->status()} — {$response->body()}"
            );
        }

        $this->logger->logApiCall(
            $processingId,
            'LocalWhisper',
            'audio/transcriptions',
            microtime(true) - $apiStartTime,
            200,
            null,
            ['response_format' => 'verbose_json']
        );

        $payload = $response->json();

        if (! is_array($payload) || ! is_array($payload['segments'] ?? null) || $payload['segments'] === []) {
            throw new TranscriptionException('Local Whisper verbose_json response contained no timestamped segments');
        }

        $cues = [];

        foreach ($payload['segments'] as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            $cues[] = [
                'start' => $segment['start'] ?? null,
                'end' => $segment['end'] ?? null,
                'text' => $segment['text'] ?? null,
            ];
        }

        $transcript = ChurchServiceTranscript::fromCues(
            $cues,
            (float) (is_numeric($payload['duration'] ?? null) ? $payload['duration'] : 0.0),
            ChurchServiceTranscript::SOURCE_LOCAL_WHISPER,
        );

        if ($transcript->isEmpty()) {
            throw new TranscriptionException('Local Whisper verbose_json segments contained no usable cues');
        }

        return $transcript;
    }
}
