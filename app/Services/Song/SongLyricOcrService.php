<?php

declare(strict_types=1);

namespace App\Services\Song;

use App\Support\OpenAiChatPayload;
use App\Support\OpenAiUsageLogger;
use App\Traits\SanitizesLogData;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;
use Symfony\Component\Process\Process;

class SongLyricOcrService
{
    use SanitizesLogData;

    /**
     * Fractions into the song segment at which frames are sampled for OCR.
     * 10% past the start avoids instrumental intros while still showing opening
     * lyrics; later samples catch songs sung back-to-back within one section.
     */
    private const array FRAME_POSITION_FRACTIONS = [0.10, 0.45, 0.80];

    /**
     * Prompt sent to the vision model.
     */
    private const string OCR_PROMPT = 'Read the projected song lyrics visible on screen. Return only the lyrics text, one line per line. If no lyrics are visible, return NONE.';

    public function extractLyrics(float $startTime, float $endTime, string $localVideoPath): ?string
    {
        $samples = $this->extractLyricsSamples($startTime, $endTime, $localVideoPath, 1);

        return $samples[0] ?? null;
    }

    /**
     * Sample several frames across the segment and OCR each, returning the distinct
     * lyric texts found in time order. Multiple samples allow callers to detect
     * back-to-back songs that the classifier merged into a single section.
     *
     * @return array<int, string>
     */
    public function extractLyricsSamples(float $startTime, float $endTime, string $localVideoPath, ?int $limit = null): array
    {
        $fractions = $limit === null
            ? self::FRAME_POSITION_FRACTIONS
            : array_slice(self::FRAME_POSITION_FRACTIONS, 0, max(1, $limit));

        $samples = [];

        foreach ($fractions as $fraction) {
            $text = $this->extractLyricsAtFraction($startTime, $endTime, $localVideoPath, $fraction);

            if ($text === null || in_array($text, $samples, true)) {
                continue;
            }

            $samples[] = $text;
        }

        return $samples;
    }

    private function extractLyricsAtFraction(
        float $startTime,
        float $endTime,
        string $localVideoPath,
        float $fraction
    ): ?string {
        $framePath = null;
        $fullFramePath = null;

        try {
            [$framePath, $fullFramePath] = $this->extractFrame($startTime, $endTime, $localVideoPath, $fraction);

            if ($framePath === null || $fullFramePath === null) {
                return null;
            }

            $ocrText = $this->callVisionApi($fullFramePath);

            return $this->parseOcrResponse($ocrText);
        } catch (\Throwable $throwable) {
            Log::warning('SongLyricOcrService: OCR failed', $this->sanitizeArrayForLog([
                'error' => $throwable->getMessage(),
            ]));

            return null;
        } finally {
            $this->cleanupFrame($framePath);
        }
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    protected function extractFrame(float $startTime, float $endTime, string $localVideoPath, float $fraction = 0.10): array
    {
        $duration = $endTime - $startTime;

        if ($duration <= 0) {
            return [null, null];
        }

        $timestamp = $startTime + ($duration * $fraction);

        $tempDisk = (string) config('media-processing.storage.temp_disk', 'local');
        $tempDir = 'temp/song-ocr';
        $frameFilename = 'ocr_frame_'.Str::uuid().'.jpg';
        $framePath = $tempDir.'/'.$frameFilename;

        Storage::disk($tempDisk)->makeDirectory($tempDir);
        $fullFramePath = Storage::disk($tempDisk)->path($framePath);

        $ffmpegPath = (string) config('media-processing.ffmpeg.ffmpeg_path', '/usr/bin/ffmpeg');

        $command = [
            $ffmpegPath,
            '-ss', (string) $timestamp,
            '-i', $localVideoPath,
            '-vframes', '1',
            '-q:v', '2',
            '-y',
            $fullFramePath,
        ];

        $process = new Process($command);
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful() || ! file_exists($fullFramePath) || filesize($fullFramePath) === 0) {
            Log::warning('SongLyricOcrService: frame extraction failed', $this->sanitizeArrayForLog([
                'video_path' => $localVideoPath,
                'timestamp' => $timestamp,
                'error' => $process->getErrorOutput(),
            ]));

            return [null, null];
        }

        return [$framePath, $fullFramePath];
    }

    protected function callVisionApi(string $fullFramePath): string
    {
        $imageData = base64_encode((string) file_get_contents($fullFramePath));
        $model = (string) config('media-processing.song_matching.ocr_model', 'gpt-5.4-mini');

        $response = OpenAI::chat()->create(OpenAiChatPayload::forModel([
            'model' => $model,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => 'data:image/jpeg;base64,'.$imageData,
                                'detail' => 'low',
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => self::OCR_PROMPT,
                        ],
                    ],
                ],
            ],
            'service_tier' => config('openai.service_tier'),
            // Headroom for reasoning models, whose hidden reasoning tokens share this budget with
            // the visible lyrics; classic models stop early so the ceiling costs nothing.
            'max_completion_tokens' => 2000,
        ], reasoningEffort: (string) config('media-processing.song_matching.ocr_reasoning_effort', 'minimal')));

        OpenAiUsageLogger::log($response, 'song_lyric_ocr', $model);

        return (string) ($response->choices[0]->message->content ?? '');
    }

    /**
     * Returns cleaned lyrics text, or null if the model indicated no lyrics were visible.
     */
    protected function parseOcrResponse(string $response): ?string
    {
        $trimmed = trim($response);

        if ($trimmed === '' || strtoupper($trimmed) === 'NONE') {
            return null;
        }

        return $trimmed;
    }

    protected function cleanupFrame(?string $framePath): void
    {
        if ($framePath === null) {
            return;
        }

        $tempDisk = (string) config('media-processing.storage.temp_disk', 'local');

        try {
            if (Storage::disk($tempDisk)->exists($framePath)) {
                Storage::disk($tempDisk)->delete($framePath);
            }
        } catch (\Throwable $throwable) {
            Log::warning('SongLyricOcrService: failed to clean up frame', $this->sanitizeArrayForLog([
                'frame_path' => (string) $framePath,
                'error' => $throwable->getMessage(),
            ]));
        }
    }
}
