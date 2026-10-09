<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use App\Enums\AudioProfile;
use App\Models\MediaProcessingLog;

/**
 * The effective treatment for one cut: a profile's configured settings with any
 * per-run overrides applied (`processing_metadata.audio_treatment_overrides`).
 *
 * {@see self::preprocessing()} is the single source of the filters that run before
 * loudness normalisation. The measuring pass and the encode both build from it, so
 * the statistics `loudnorm` is given are those of the signal it actually receives.
 */
final readonly class AudioTreatmentSettings
{
    /** Level-dependent treatments run on a part first brought to this loudness. */
    public const float WORKING_LUFS = -23.0;

    /**
     * @param  list<array{above: float, denoise: string}>  $denoiseByPause  noise-adaptive denoise, used when `$denoise` is null:
     *                                                                       the first rule whose `above` the part's pause level exceeds
     */
    public function __construct(
        public AudioProfile $profile,
        public float $targetLufs,
        public float $truePeak,
        public float $lra,
        public float $maxGainDb,
        public ?float $highPassHz = null,
        public ?float $humHz = null,
        public ?string $denoise = null,
        public ?string $deEss = null,
        public ?string $dynamics = null,
        public array $denoiseByPause = [],
    ) {}

    /** @param  array<string, mixed>  $overrides */
    public static function for(AudioProfile $profile, array $overrides = []): self
    {
        $settings = array_replace((array) config('media-processing.audio_treatment.'.$profile->value, []), $overrides);
        $number = static fn (string $key): ?float => is_numeric($settings[$key] ?? null) ? (float) $settings[$key] : null;
        $option = static fn (string $key): ?string => is_string($settings[$key] ?? null) && $settings[$key] !== '' ? $settings[$key] : null;

        return new self(
            profile: $profile,
            targetLufs: $number('target_lufs') ?? -16.0,
            truePeak: $number('true_peak') ?? -1.5,
            lra: $number('lra') ?? 11.0,
            maxGainDb: $number('max_gain_db') ?? 40.0,
            highPassHz: $number('high_pass_hz'),
            humHz: $number('hum_hz'),
            // "off" turns denoising off for a recording, adaptive choice included.
            denoise: $option('denoise') === 'off' ? null : $option('denoise'),
            deEss: $option('de_ess'),
            dynamics: $option('dynamics'),
            denoiseByPause: $option('denoise') === 'off' ? [] : self::pauseRules($settings['denoise_by_pause'] ?? []),
        );
    }

    /** @return list<array{above: float, denoise: string}> */
    private static function pauseRules(mixed $rules): array
    {
        $valid = array_values(array_filter(is_array($rules) ? $rules : [], static fn (mixed $rule): bool => is_array($rule)
            && is_numeric($rule['above'] ?? null) && is_string($rule['denoise'] ?? null) && $rule['denoise'] !== ''));

        return array_map(static fn (array $rule): array => ['above' => (float) $rule['above'], 'denoise' => (string) $rule['denoise']], $valid);
    }

    /** Whether each part's denoise is chosen from how loud its pauses are. */
    public function choosesDenoiseByPause(): bool
    {
        return $this->denoise === null && $this->denoiseByPause !== [];
    }

    /**
     * The denoise for a part whose pauses sit `$pauseRelative` dB from its loudness:
     * louder pauses (more room noise) get stronger treatment, gated silence none.
     */
    public function denoiseForPause(float $pauseRelative): ?string
    {
        foreach ($this->denoiseByPause as $rule) {
            if ($pauseRelative > $rule['above']) {
                return $rule['denoise'];
            }
        }

        return null;
    }

    /** These settings with one part's chosen denoise. */
    public function withDenoise(?string $denoise): self
    {
        return new self($this->profile, $this->targetLufs, $this->truePeak, $this->lra, $this->maxGainDb,
            $this->highPassHz, $this->humHz, $denoise, $this->deEss, $this->dynamics);
    }

    /**
     * A run's own settings for a profile, e.g. hum removal for a recording that hums.
     *
     * @return array<string, mixed>
     */
    public static function overridesFor(MediaProcessingLog $run, AudioProfile $profile): array
    {
        $overrides = $run->processing_metadata?->toArray()['audio_treatment_overrides'][$profile->value] ?? [];

        return is_array($overrides) ? $overrides : [];
    }

    /** Speech is heard as one voice: mixed to one channel before it is measured. */
    public function isMono(): bool
    {
        return $this->profile === AudioProfile::Speech;
    }

    /**
     * Denoising, de-essing and dynamics act on absolute levels, so a part is first
     * brought to {@see self::WORKING_LUFS} when any of them is on.
     */
    public function needsWorkingLevel(): bool
    {
        return $this->denoise !== null || $this->deEss !== null || $this->dynamics !== null;
    }

    /**
     * Filters before loudness normalisation, in order. `$workingGainDb` is the gain to
     * {@see self::WORKING_LUFS}, required when {@see self::needsWorkingLevel()}.
     *
     * @return list<string>
     */
    public function preprocessing(?float $workingGainDb = null): array
    {
        $filters = $this->levelIndependent();

        if ($this->needsWorkingLevel()) {
            $filters[] = 'volume='.$this->number($workingGainDb ?? 0.0).'dB';
        }

        if ($this->denoise !== null) {
            $filters[] = 'afftdn='.$this->denoise;
        }

        if ($this->deEss !== null) {
            $filters[] = 'deesser='.$this->deEss;
        }

        if ($this->dynamics !== null) {
            $filters[] = $this->dynamics;
        }

        return $filters;
    }

    /**
     * The channel mix and the fixed filters: what the working level is measured through.
     *
     * @return list<string>
     */
    public function levelIndependent(): array
    {
        $filters = $this->isMono() ? ['aformat=channel_layouts=mono'] : [];

        if ($this->highPassHz !== null) {
            $filters[] = 'highpass=f='.$this->number($this->highPassHz);
        }

        if ($this->humHz !== null) {
            foreach ([1, 2, 3] as $harmonic) {
                $filters[] = 'bandreject=f='.$this->number($this->humHz * $harmonic).':width_type=q:w=30';
            }
        }

        return $filters;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'profile' => $this->profile->value,
            'target_lufs' => $this->targetLufs,
            'true_peak' => $this->truePeak,
            'lra' => $this->lra,
            'max_gain_db' => $this->maxGainDb,
            'high_pass_hz' => $this->highPassHz,
            'hum_hz' => $this->humHz,
            'denoise' => $this->denoise,
            'de_ess' => $this->deEss,
            'dynamics' => $this->dynamics,
            'denoise_by_pause' => $this->denoiseByPause,
        ];
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');
    }
}
