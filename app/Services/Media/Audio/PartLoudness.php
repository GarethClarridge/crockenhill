<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

/**
 * One part's loudness as the normalising encode will see it, or why it cannot be
 * normalised. An untreated part is left as recorded and reported, never given
 * made-up statistics.
 */
final readonly class PartLoudness
{
    public const string TooShort = 'too_short';

    public const string Silent = 'silent';

    public const string Unmeasurable = 'unmeasurable';

    public const string TooQuiet = 'too_quiet';

    /**
     * @param  array{input_i: float, input_tp: float, input_lra: float, input_thresh: float, target_offset: float}|null  $measured  the processed first pass
     * @param  array{input_i: float, input_tp: float, input_lra: float, input_thresh: float, target_offset: float}|null  $raw  the diagnostic pass before level-dependent treatment, when one ran
     * @param  string|null  $denoise  the denoise this part was given (afftdn options), chosen or configured
     * @param  float|null  $pauseRelative  its pause level relative to its loudness, when that chose the denoise
     */
    public function __construct(
        public float $duration,
        public ?array $measured,
        public ?string $untreatedReason = null,
        public ?float $workingGainDb = null,
        public ?array $raw = null,
        public ?string $denoise = null,
        public ?float $pauseRelative = null,
    ) {}

    /** @param  array{input_i: float, input_tp: float, input_lra: float, input_thresh: float, target_offset: float}|null  $raw */
    public static function untreated(float $duration, string $reason, ?array $raw = null): self
    {
        return new self($duration, null, $reason, raw: $raw);
    }

    public function isTreated(): bool
    {
        return $this->measured !== null;
    }

    /**
     * The mode `loudnorm` will choose given these statistics. Linear gain needs the
     * raised peak to stay under the ceiling and the range to fit; otherwise it rides
     * the gain dynamically (FFmpeg `af_loudnorm.c`, init).
     */
    public function expectedMode(AudioTreatmentSettings $settings): ?string
    {
        if ($this->measured === null) {
            return null;
        }

        $gain = $settings->targetLufs - $this->measured['input_i'];

        return $this->measured['input_tp'] + $gain <= $settings->truePeak && $this->measured['input_lra'] <= $settings->lra
            ? 'linear'
            : 'dynamic';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'duration' => round($this->duration, 3),
            'untreated_reason' => $this->untreatedReason,
            'working_gain_db' => $this->workingGainDb === null ? null : round($this->workingGainDb, 2),
            'raw' => $this->raw,
            'pause_relative' => $this->pauseRelative === null ? null : round($this->pauseRelative, 1),
            'denoise' => $this->denoise,
            'measured' => $this->measured,
        ];
    }
}
