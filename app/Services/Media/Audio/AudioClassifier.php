<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use UnexpectedValueException;

/**
 * Runs `scripts/classify_audio.py` over a local audio file: as a command, or through its service
 * on the Mac when `media-processing.audio_classifier.url` is set.
 *
 * The script is the only place the audio is decoded and scored, so its preprocessing and model
 * revision are recorded in its own output. This refuses anything short of a complete, valid
 * timeline for exactly the file it was given: a non-zero exit, output that is not JSON, a
 * malformed or short timeline, or a hash of a different file.
 */
final class AudioClassifier
{
    /**
     * A 75-minute service is ~900 windows; the container measured ~0.95 s a window on 2026-09-25
     * (run 1304, 66 min, 746 s).
     */
    public const int TIMEOUT_SECONDS = 3600;

    /**
     * The window `scripts/classify_audio.py` scores; two artefacts of one recording agree on its
     * duration to within one.
     */
    public const float WINDOW_SECONDS = 5.0;

    /**
     * Output-identical to one window at a time (0 class changes in 789 windows of run 1304), and
     * ~10% faster in the container.
     */
    private const int BATCH_SIZE = 16;

    /**
     * @return array<string, mixed> The artifact, validated by {@see AudioTimeline::fromArray()}
     *
     * @throws RuntimeException When the classifier fails or its output cannot be trusted
     */
    public function classify(string $localAudioPath): array
    {
        if (! is_file($localAudioPath)) {
            throw new RuntimeException("Audio to classify not found: {$localAudioPath}");
        }

        $serviceUrl = config('media-processing.audio_classifier.url');
        $payload = is_string($serviceUrl) && $serviceUrl !== ''
            ? $this->viaService($serviceUrl, $localAudioPath)
            : $this->viaCommand($localAudioPath);

        try {
            AudioTimeline::fromArray($payload);
        } catch (UnexpectedValueException $exception) {
            throw new RuntimeException('Audio classifier returned an invalid timeline: '.$exception->getMessage(), previous: $exception);
        }

        $expectedHash = hash_file('sha256', $localAudioPath);

        if (($payload['input_sha256'] ?? null) !== $expectedHash) {
            throw new RuntimeException('Audio classifier reported a different input hash than the file it was given.');
        }

        return $payload;
    }

    /**
     * @return array<mixed>
     */
    private function viaCommand(string $localAudioPath): array
    {
        $result = Process::timeout(self::TIMEOUT_SECONDS)->run([
            'python3',
            base_path('scripts/classify_audio.py'),
            $localAudioPath,
            '--batch-size',
            (string) self::BATCH_SIZE,
        ]);

        if (! $result->successful()) {
            throw new RuntimeException('Audio classifier failed: '.trim($result->errorOutput()));
        }

        $payload = json_decode($result->output(), true);

        if (! is_array($payload)) {
            throw new RuntimeException('Audio classifier returned output that is not a JSON object.');
        }

        return $payload;
    }

    /**
     * Upload the audio to `scripts/classify_audio_server.py`. The bytes travel, not a path, so
     * the service needs no view of the container's mounts.
     *
     * @return array<mixed>
     */
    private function viaService(string $serviceUrl, string $localAudioPath): array
    {
        $url = rtrim($serviceUrl, '/').'/classify';
        $stream = fopen($localAudioPath, 'rb');

        if (! is_resource($stream)) {
            throw new RuntimeException("Unable to open audio to classify: {$localAudioPath}");
        }

        // The PSR stream owns the handle and closes it when it is released.
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withBody(Utils::streamFor($stream), 'application/octet-stream')
                ->post($url);
        } catch (ConnectionException $exception) {
            throw new RuntimeException("Audio classifier service unreachable at {$url}: {$exception->getMessage()}", previous: $exception);
        }

        if (! $response->successful()) {
            $error = $response->json('error');

            throw new RuntimeException(sprintf('Audio classifier service failed (HTTP %d): %s', $response->status(), is_string($error) ? $error : trim($response->body())));
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Audio classifier service returned a body that is not a JSON object.');
        }

        return $payload;
    }
}
