<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Storage;

/**
 * A `scripts/classify_audio.py` artifact built from spans of scores, for tests that cannot run
 * the classifier.
 */
final class AudioTimelineFixture
{
    public const string MODEL = 'MIT/ast-finetuned-audioset-10-10-0.4593';

    public const string MODEL_REVISION = 'f826b80d28226b62986cc218e5cec390b1096902';

    /**
     * Each span is [from, to, music, speech] or [from, to, music, speech, singing]; time no span
     * covers is neither (0.05, 0.05) and unsung. The last window is shortened to the audio's end, as
     * the script writes it.
     *
     * @param  list<array{0: float|int, 1: float|int, 2: float, 3: float, 4?: float}>  $spans
     * @return array<string, mixed>
     */
    public static function payload(array $spans, float $audioSeconds, string $inputSha256 = 'fixture'): array
    {
        $windows = [];

        for ($start = 0.0; $start < $audioSeconds; $start += 5.0) {
            $end = min($start + 5.0, $audioSeconds);
            $mid = ($start + $end) / 2;
            [$music, $speech, $singing] = [0.05, 0.05, 0.0];

            foreach ($spans as $span) {
                if ($mid >= $span[0] && $mid < $span[1]) {
                    [$music, $speech, $singing] = [$span[2], $span[3], $span[4] ?? 0.0];
                }
            }

            $windows[] = ['start' => $start, 'end' => $end, 'music' => $music, 'speech' => $speech, 'singing' => $singing, 'choir' => 0.0];
        }

        return [
            'model' => self::MODEL,
            'model_revision' => self::MODEL_REVISION,
            'window_seconds' => 5,
            'audio_seconds' => $audioSeconds,
            'input_sha256' => $inputSha256,
            'preprocessing' => ['decoder' => 'fixture'],
            'runtime' => ['device' => 'cpu'],
            'windows' => $windows,
        ];
    }

    /**
     * @param  list<array{0: float|int, 1: float|int, 2: float, 3: float, 4?: float}>  $spans
     */
    public static function json(array $spans, float $audioSeconds, string $inputSha256 = 'fixture'): string
    {
        return (string) json_encode(self::payload($spans, $audioSeconds, $inputSha256));
    }

    /**
     * Store a timeline that hears neither music nor speech, returning its path.
     */
    public static function put(string $disk, string $path, float $audioSeconds = 2430.0): string
    {
        Storage::disk($disk)->put($path, self::json([], $audioSeconds));

        return $path;
    }
}
