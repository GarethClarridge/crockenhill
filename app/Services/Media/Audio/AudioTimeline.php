<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use App\Enums\SoundClass;
use UnexpectedValueException;

/**
 * Where a recording holds music and speech, as a trained audio classifier heard it.
 *
 * Whisper writes a filler cue ("Thank you.", ". . .") over congregational singing it cannot hear,
 * and the RMS level cannot tell a leader at a microphone from singing. The AudioSet-trained
 * classifier (`scripts/classify_audio.py`) scores music and speech independently in 5 s windows;
 * this reads its artifact. The cut-offs were validated on 2026-09-25 against a blind listening set
 * whose predictions were sealed first: of 171 scored windows, music 78/78 read as music and one of
 * 93 speech windows as music (`docs/plans/MUSIC-AND-SILENCE-DETECTION-2026-09-25.md` §6.3).
 *
 * It does not tell singing from instrumental music, and its silence score is weak: digital
 * silence comes from the RMS log, not from here.
 */
final readonly class AudioTimeline
{
    public const float MUSIC_CUTOFF = 0.4;

    public const float SPEECH_CUTOFF = 0.5;

    private const float TOLERANCE_SECONDS = 0.01;

    /**
     * @param  list<array{start: float, end: float, music: float, speech: float}>  $windows  Contiguous from 0
     */
    private function __construct(
        public string $model,
        public string $modelRevision,
        public float $windowSeconds,
        public float $audioSeconds,
        public array $windows,
    ) {}

    /**
     * @throws UnexpectedValueException When the artifact is malformed or does not cover the audio
     */
    public static function fromJson(string $content): self
    {
        $payload = json_decode($content, true);

        if (! is_array($payload)) {
            throw new UnexpectedValueException('Audio timeline is not a JSON object.');
        }

        return self::fromArray($payload);
    }

    /**
     * @param  array<mixed>  $payload
     *
     * @throws UnexpectedValueException When the artifact is malformed or does not cover the audio
     */
    public static function fromArray(array $payload): self
    {
        $model = $payload['model'] ?? null;
        $modelRevision = $payload['model_revision'] ?? null;
        $windowSeconds = $payload['window_seconds'] ?? null;
        $audioSeconds = $payload['audio_seconds'] ?? null;
        $rawWindows = $payload['windows'] ?? null;

        if (! is_string($model) || $model === '' || ! is_string($modelRevision) || $modelRevision === '') {
            throw new UnexpectedValueException('Audio timeline does not name its model and revision.');
        }

        if (! is_numeric($windowSeconds) || (float) $windowSeconds <= 0.0 || ! is_numeric($audioSeconds) || (float) $audioSeconds <= 0.0) {
            throw new UnexpectedValueException('Audio timeline has no window length or audio duration.');
        }

        if (! is_array($rawWindows) || $rawWindows === []) {
            throw new UnexpectedValueException('Audio timeline has no windows.');
        }

        $windowSeconds = (float) $windowSeconds;
        $audioSeconds = (float) $audioSeconds;
        $windows = [];
        $expectedStart = 0.0;

        foreach (array_values($rawWindows) as $position => $window) {
            if (! is_array($window)) {
                throw new UnexpectedValueException("Audio timeline window {$position} is not an object.");
            }

            foreach (['start', 'end', 'music', 'speech'] as $key) {
                if (! is_numeric($window[$key] ?? null)) {
                    throw new UnexpectedValueException("Audio timeline window {$position} has no {$key}.");
                }
            }

            $start = (float) $window['start'];
            $end = (float) $window['end'];
            $music = (float) $window['music'];
            $speech = (float) $window['speech'];

            if (abs($start - $expectedStart) > self::TOLERANCE_SECONDS) {
                throw new UnexpectedValueException(sprintf('Audio timeline window %d starts at %.3fs, not %.3fs: windows must be contiguous from 0.', $position, $start, $expectedStart));
            }

            if ($end <= $start || $end - $start > $windowSeconds + self::TOLERANCE_SECONDS) {
                throw new UnexpectedValueException(sprintf('Audio timeline window %d (%.3fs-%.3fs) is not a window of at most %.1fs.', $position, $start, $end, $windowSeconds));
            }

            if ($music < 0.0 || $music > 1.0 || $speech < 0.0 || $speech > 1.0) {
                throw new UnexpectedValueException("Audio timeline window {$position} has a score outside 0-1.");
            }

            $windows[] = ['start' => $start, 'end' => $end, 'music' => $music, 'speech' => $speech];
            $expectedStart = $end;
        }

        if (abs($expectedStart - $audioSeconds) > $windowSeconds) {
            throw new UnexpectedValueException(sprintf('Audio timeline covers %.1fs of %.1fs of audio.', $expectedStart, $audioSeconds));
        }

        return new self($model, $modelRevision, $windowSeconds, $audioSeconds, $windows);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'model_revision' => $this->modelRevision,
            'window_seconds' => $this->windowSeconds,
            'audio_seconds' => $this->audioSeconds,
            'windows' => $this->windows,
        ];
    }

    public function end(): float
    {
        return $this->windows[count($this->windows) - 1]['end'];
    }

    public function windowCount(): int
    {
        return count($this->windows);
    }

    /**
     * The window holding a time, or null outside the classified audio.
     */
    public function windowAt(float $time): ?int
    {
        if ($time < 0.0 || $time >= $this->end()) {
            return null;
        }

        return min($this->windowCount() - 1, (int) floor($time / $this->windowSeconds));
    }

    public function classOf(int $window): SoundClass
    {
        return SoundClass::fromScores(
            $this->windows[$window]['music'],
            $this->windows[$window]['speech'],
            self::MUSIC_CUTOFF,
            self::SPEECH_CUTOFF,
        );
    }

    /**
     * The share of a span, by overlap seconds, in windows whose music score reaches the cut-off.
     * A mixed window counts: it holds music.
     */
    public function musicShare(float $from, float $to): float
    {
        return $this->share($from, $to, fn (array $window): bool => $window['music'] >= self::MUSIC_CUTOFF);
    }

    /**
     * The share of a span, by overlap seconds, in windows whose speech score reaches the cut-off.
     */
    public function speechShare(float $from, float $to): float
    {
        return $this->share($from, $to, fn (array $window): bool => $window['speech'] >= self::SPEECH_CUTOFF);
    }

    /**
     * The share of a span, by overlap seconds, in windows of exactly one class. Unlike
     * {@see self::musicShare()}, a mixed window is not music here.
     */
    public function classShare(SoundClass $class, float $from, float $to): float
    {
        return $this->share($from, $to, fn (array $window): bool => SoundClass::fromScores(
            $window['music'],
            $window['speech'],
            self::MUSIC_CUTOFF,
            self::SPEECH_CUTOFF,
        ) === $class);
    }

    /**
     * Maximal runs of consecutive windows of one class lasting at least the minimum.
     *
     * @return list<array{0: float, 1: float}>
     */
    public function spans(SoundClass $class, float $minimumSeconds = 0.0): array
    {
        $spans = [];
        $openStart = null;

        foreach ($this->windows as $index => $window) {
            if ($this->classOf($index) === $class) {
                $openStart ??= $window['start'];

                continue;
            }

            if ($openStart !== null && $minimumSeconds <= $window['start'] - $openStart) {
                $spans[] = [$openStart, $window['start']];
            }

            $openStart = null;
        }

        if ($openStart !== null && $this->end() - $openStart >= $minimumSeconds) {
            $spans[] = [$openStart, $this->end()];
        }

        return $spans;
    }

    /**
     * @param  callable(array{start: float, end: float, music: float, speech: float}): bool  $counts
     */
    private function share(float $from, float $to, callable $counts): float
    {
        if ($to <= $from) {
            return 0.0;
        }

        $counted = 0.0;

        foreach ($this->windows as $window) {
            $overlap = min($to, $window['end']) - max($from, $window['start']);

            if ($overlap > 0.0 && $counts($window)) {
                $counted += $overlap;
            }
        }

        return $counted / ($to - $from);
    }
}
