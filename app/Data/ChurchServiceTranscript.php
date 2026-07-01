<?php

declare(strict_types=1);

namespace App\Data;

/**
 * A full-service timestamped transcript: the ordered timed cues produced by a
 * single whole-recording transcription pass, used as the evidence base for
 * LLM-first service structure detection.
 */
final readonly class ChurchServiceTranscript extends JsonData
{
    public const SOURCE_WHISPER_API = 'whisper_api';

    public const SOURCE_LOCAL_WHISPER = 'local_whisper';

    public const SOURCE_MOCK = 'mock';

    public const SOURCE_SIDECAR = 'sidecar';

    /**
     * @param  list<array{start: float, end: float, text: string}>  $cues  Ordered by start time
     * @param  float  $duration  Total recording duration in seconds
     * @param  string  $source  One of the SOURCE_* constants
     */
    public function __construct(
        public array $cues,
        public float $duration,
        public string $source,
    ) {}

    /**
     * Build a transcript from raw cue data, normalising and ordering as it goes.
     *
     * Cues with empty text or non-numeric timings are dropped; end times are
     * clamped to be at least the start time; cues are sorted chronologically.
     *
     * @param  array<int, mixed>  $cues
     */
    public static function fromCues(array $cues, float $duration, string $source): self
    {
        $normalised = [];

        foreach ($cues as $cue) {
            if (! is_array($cue)) {
                continue;
            }

            $start = $cue['start'] ?? null;
            $end = $cue['end'] ?? null;
            $text = $cue['text'] ?? null;

            if (! is_numeric($start) || ! is_numeric($end) || ! is_string($text)) {
                continue;
            }

            $trimmedText = trim($text);
            if ($trimmedText === '') {
                continue;
            }

            $startSeconds = max(0.0, (float) $start);

            $normalised[] = [
                'start' => $startSeconds,
                'end' => max($startSeconds, (float) $end),
                'text' => $trimmedText,
            ];
        }

        usort($normalised, static fn (array $left, array $right): int => $left['start'] <=> $right['start']);

        $lastCueEnd = $normalised === [] ? 0.0 : max(array_column($normalised, 'end'));

        return new self(
            cues: $normalised,
            duration: max(0.0, $duration, $lastCueEnd),
            source: $source,
        );
    }

    public static function fromArray(mixed $value): self
    {
        $payload = self::arrayValue($value);

        return self::fromCues(
            is_array($payload['cues'] ?? null) ? $payload['cues'] : [],
            (float) (self::floatOrNull($payload['duration'] ?? null) ?? 0.0),
            self::stringOrNull($payload['source'] ?? null) ?? self::SOURCE_MOCK,
        );
    }

    /**
     * @return array{cues: list<array{start: float, end: float, text: string}>, duration: float, source: string}
     */
    public function toArray(): array
    {
        return [
            'cues' => $this->cues,
            'duration' => $this->duration,
            'source' => $this->source,
        ];
    }

    /**
     * Compact `[mm:ss] text` lines for inclusion in an LLM prompt.
     *
     * Minutes count up past 59 (e.g. `[92:15]`) so every timestamp stays a
     * simple minutes-and-seconds pair regardless of recording length.
     */
    public function toPromptText(): string
    {
        $lines = array_map(
            static function (array $cue): string {
                $totalSeconds = (int) floor($cue['start']);

                return sprintf('[%d:%02d] %s', intdiv($totalSeconds, 60), $totalSeconds % 60, $cue['text']);
            },
            $this->cues
        );

        return implode("\n", $lines);
    }

    /**
     * The spoken text of every cue overlapping the given time window.
     */
    public function sliceText(float $start, float $end): string
    {
        $texts = [];

        foreach ($this->cues as $cue) {
            if ($cue['end'] > $start && $cue['start'] < $end) {
                $texts[] = $cue['text'];
            }
        }

        return implode(' ', $texts);
    }

    /**
     * Total seconds covered by cues — the recording's speech time, used for
     * structure coverage checks.
     */
    public function speechDuration(): float
    {
        return array_sum(array_map(
            static fn (array $cue): float => $cue['end'] - $cue['start'],
            $this->cues
        ));
    }

    public function isEmpty(): bool
    {
        return $this->cues === [];
    }
}
