<?php

declare(strict_types=1);

namespace App\Services;

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
     * Fraction into the song segment at which to extract the frame.
     * 10% past the start avoids instrumental intros while still showing opening lyrics.
     */
    private const float FRAME_POSITION_FRACTION = 0.10;

    /**
     * Prompt sent to the vision model.
     */
    private const string OCR_PROMPT = 'Read the projected song lyrics visible on screen. Return only the lyrics text, one line per line. If no lyrics are visible, return NONE.';

    public function extractLyrics(float $startTime, float $endTime, string $localVideoPath): ?string
    {
        $framePath = null;
        $fullFramePath = null;

        try {
            [$framePath, $fullFramePath] = $this->extractFrame($startTime, $endTime, $localVideoPath);

            if ($framePath === null || $fullFramePath === null) {
                return null;
            }

            $ocrText = $this->callVisionApi($fullFramePath);

            return $this->parseOcrResponse($ocrText);
        } catch (\Throwable $throwable) {
            Log::warning('SongLyricOcrService: OCR failed', [
                'error' => $this->sanitizeForLog($throwable->getMessage()),
            ]);

            return null;
        } finally {
            $this->cleanupFrame($framePath);
        }
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    protected function extractFrame(float $startTime, float $endTime, string $localVideoPath): array
    {
        $duration = $endTime - $startTime;

        if ($duration <= 0) {
            return [null, null];
        }

        $timestamp = $startTime + ($duration * self::FRAME_POSITION_FRACTION);

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
            Log::warning('SongLyricOcrService: frame extraction failed', [
                'video_path' => $this->sanitizeForLog($localVideoPath),
                'timestamp' => $timestamp,
                'error' => $this->sanitizeForLog($process->getErrorOutput()),
            ]);

            return [null, null];
        }

        return [$framePath, $fullFramePath];
    }

    protected function callVisionApi(string $fullFramePath): string
    {
        $imageData = base64_encode((string) file_get_contents($fullFramePath));
        $model = (string) config('media-processing.song_matching.ocr_model', 'gpt-4o-mini');

        $response = OpenAI::chat()->create([
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
            'max_tokens' => 300,
        ]);

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
            Log::warning('SongLyricOcrService: failed to clean up frame', [
                'frame_path' => $this->sanitizeForLog((string) $framePath),
                'error' => $this->sanitizeForLog($throwable->getMessage()),
            ]);
        }
    }
}
