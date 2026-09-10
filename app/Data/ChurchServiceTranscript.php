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
     * @param  list<array{start: float, end: float, reason: string}>  $unobservableWindows
     */
    public function __construct(
        public array $cues,
        public float $duration,
        public string $source,
        public array $unobservableWindows = [],
    ) {}

    /**
     * Build a transcript from raw cue data, normalising and ordering as it goes.
     *
     * Cues with empty text or non-numeric timings are dropped; end times are
     * clamped to be at least the start time; cues are sorted chronologically.
     *
     * **A supplied positive duration is the recording's true length and wins.**
     * Every caller measures the audio the cues were produced from — the API's
     * reported duration, `getAudioDuration()`, a retry clip's own length — so a
     * cue reaching past it is describing time the media does not contain, and
     * cue ends and unobservable windows are clamped back to it. Whisper pads a
     * final partial window out to a full window and transcribes the padding,
     * which is where those cues come from: across the historic corpus every one
     * of them reads "Thank you.".
     *
     * This used to take `max($duration, $lastCueEnd)`, which let that padding
     * lengthen the recording. P8-Q17 traced 16 impossible section ends to it:
     * structure detection reads this duration, ended the closing section at the
     * hallucinated cue, and FFmpeg — which stops at EOF whatever it is asked
     * for — then emitted a clip shorter than the row claimed. §3704's row said
     * 52.01s and its video is 23.43s.
     *
     * The fall-back to cue extent survives for `$duration <= 0`, where nothing
     * measured the media and the cues are the only evidence of its length.
     *
     * @param  array<int, mixed>  $cues
     * @param  array<int, mixed>  $unobservableWindows
     */
    public static function fromCues(array $cues, float $duration, string $source, array $unobservableWindows = []): self
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

        $normalisedWindows = self::normaliseUnobservableWindows($unobservableWindows);
        $measured = $duration > 0.0 ? $duration : null;

        if ($measured !== null) {
            $held = [];

            foreach ($normalised as $cue) {
                $end = self::heldWithin($cue['start'], $cue['end'], $measured);

                if ($end !== null) {
                    $held[] = ['start' => $cue['start'], 'end' => $end, 'text' => $cue['text']];
                }
            }

            $normalised = $held;
            $heldWindows = [];

            foreach ($normalisedWindows as $window) {
                $end = self::heldWithin($window['start'], $window['end'], $measured);

                if ($end !== null) {
                    $heldWindows[] = ['start' => $window['start'], 'end' => $end, 'reason' => $window['reason']];
                }
            }

            $normalisedWindows = $heldWindows;
        }

        $lastCueEnd = $normalised === [] ? 0.0 : max(array_column($normalised, 'end'));
        $lastWindowEnd = $normalisedWindows === [] ? 0.0 : max(array_column($normalisedWindows, 'end'));

        return new self(
            cues: $normalised,
            duration: $measured ?? max(0.0, $lastCueEnd, $lastWindowEnd),
            source: $source,
            unobservableWindows: $normalisedWindows,
        );
    }

    /**
     * The end this interval may keep inside a recording of `$duration`, or null
     * where the interval lies wholly outside it.
     *
     * An interval starting at or after the end is dropped outright — there is
     * no media under any of it. One that merely overruns is truncated, because
     * the part before the end is real: the padded final window of a 2370.34s
     * recording still covers its last 0.16 seconds of genuine audio, and
     * discarding the whole cue would throw that away with the padding.
     *
     * Only the timings are judged. Whether the text over a surviving sliver is
     * a hallucination is a separate question this cannot answer, and answering
     * it here would delete evidence on a guess.
     */
    private static function heldWithin(float $start, float $end, float $duration): ?float
    {
        return $start >= $duration ? null : min($end, $duration);
    }

    public static function fromArray(mixed $value): self
    {
        $payload = self::arrayValue($value);

        return self::fromCues(
            is_array($payload['cues'] ?? null) ? $payload['cues'] : [],
            (float) (self::floatOrNull($payload['duration'] ?? null) ?? 0.0),
            self::stringOrNull($payload['source'] ?? null) ?? self::SOURCE_MOCK,
            is_array($payload['unobservable_windows'] ?? null) ? $payload['unobservable_windows'] : [],
        );
    }

    /**
     * @return array{cues: list<array{start: float, end: float, text: string}>, duration: float, source: string, unobservable_windows: list<array{start: float, end: float, reason: string}>}
     */
    public function toArray(): array
    {
        return [
            'cues' => $this->cues,
            'duration' => $this->duration,
            'source' => $this->source,
            'unobservable_windows' => $this->unobservableWindows,
        ];
    }

    /**
     * Compact `[mm:ss-mm:ss] text` lines for inclusion in an LLM prompt.
     *
     * Each cue carries both its start and end so the detector can ground every
     * section boundary — including section ends — in a supplied timestamp.
     * Minutes count up past 59 (e.g. `[92:15]`) so every timestamp stays a
     * simple minutes-and-seconds pair regardless of recording length.
     */
    public function toPromptText(): string
    {
        $format = static function (float $seconds): string {
            $totalSeconds = (int) floor($seconds);

            return sprintf('%d:%02d', intdiv($totalSeconds, 60), $totalSeconds % 60);
        };

        $entries = array_map(
            static fn (array $cue): array => [
                'start' => $cue['start'],
                'line' => sprintf(
                    '[%s-%s] %s',
                    $format($cue['start']),
                    $format($cue['end']),
                    $cue['text']
                ),
            ],
            $this->cues
        );

        foreach ($this->unobservableWindows as $window) {
            $entries[] = [
                'start' => $window['start'],
                'line' => sprintf(
                    '[%s-%s] TRANSCRIPT UNOBSERVABLE',
                    $format($window['start']),
                    $format($window['end']),
                ),
            ];
        }

        usort($entries, static fn (array $left, array $right): int => $left['start'] <=> $right['start']);

        return implode("\n", array_column($entries, 'line'));
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
     * The spoken text of every cue overlapping any of the given time windows.
     *
     * A concatenated extraction joins several source spans and drops what lies
     * between them, so the sermon's own transcript must be selected the same
     * way: slicing the outer bounds instead would bank the intervening hymn or
     * notices as sermon text and feed them to later analysis. Each cue is
     * emitted once even when the windows overlap, and always in recording
     * order, so the result reads as continuous speech.
     *
     * @param  list<array{start: float, end: float}>  $spans
     */
    public function sliceTextForSpans(array $spans): string
    {
        $texts = [];

        foreach ($this->cues as $cue) {
            foreach ($spans as $span) {
                if ($cue['end'] > $span['start'] && $cue['start'] < $span['end']) {
                    $texts[] = $cue['text'];

                    break;
                }
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

    /**
     * @param  array<int, mixed>  $windows
     * @return list<array{start: float, end: float, reason: string}>
     */
    private static function normaliseUnobservableWindows(array $windows): array
    {
        $normalised = [];

        foreach ($windows as $window) {
            if (! is_array($window) || ! is_numeric($window['start'] ?? null) || ! is_numeric($window['end'] ?? null)) {
                continue;
            }

            $reason = $window['reason'] ?? null;
            if (! is_string($reason) || trim($reason) === '') {
                continue;
            }

            $start = max(0.0, (float) $window['start']);
            $normalised[] = [
                'start' => $start,
                'end' => max($start, (float) $window['end']),
                'reason' => trim($reason),
            ];
        }

        usort($normalised, static fn (array $left, array $right): int => $left['start'] <=> $right['start']);

        return $normalised;
    }
}
