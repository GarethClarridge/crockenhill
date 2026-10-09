<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use App\Exceptions\VideoProcessingException;
use Symfony\Component\Process\Process;

/**
 * Measures, normalises and verifies the sound of each part of a cut
 * (VIDEO-BOUNDARY-CONSISTENCY §6.3).
 *
 * Every part is normalised on its own before the parts are joined: a 3-minute
 * reading at −47 LUFS joined to a sermon at −27 is still 20 LU quieter after any
 * whole-file normalisation. The first pass measures exactly the samples and
 * preceding filters the encode will normalise; the encoded result is measured
 * again, part by part, and any target it missed is reported for review.
 */
class SectionAudioTreatment
{
    /**
     * The filters a part's sound passes through before the join: its trim, the
     * treatment, normalisation, and a common 48 kHz stereo format.
     */
    public function partFilter(float $duration, AudioTreatmentSettings $settings, PartLoudness $loudness): string
    {
        $filters = [$this->trim($duration)];

        $measured = $loudness->measured;

        if ($measured === null) {
            // Left as recorded: only the channel mix, so every part joins in one format.
            $filters = [...$filters, ...($settings->isMono() ? ['aformat=channel_layouts=mono'] : [])];
        } else {
            $filters = [...$filters, ...$settings->withDenoise($loudness->denoise)->preprocessing($loudness->workingGainDb), sprintf(
                '%s:measured_I=%.2f:measured_TP=%.2f:measured_LRA=%.2f:measured_thresh=%.2f:offset=%.2f:linear=true:print_format=json',
                $this->loudnorm($settings),
                $measured['input_i'],
                $measured['input_tp'],
                $measured['input_lra'],
                $measured['input_thresh'],
                $measured['target_offset'],
            )];
        }

        // loudnorm works at 192 kHz and its lookahead flush can skip timestamps; rebuild them from the samples.
        // Mono speech goes to both speakers at full level: FFmpeg's own upmix would lower each by 3 dB.
        $channels = $settings->isMono() ? 'pan=stereo|c0=c0|c1=c0' : 'aformat=channel_layouts=stereo';

        return implode(',', [...$filters, 'aresample=48000', $channels, 'asetpts=N/SR/TB']);
    }

    /**
     * Measure one part of `$input` as the encode will see it.
     *
     * @throws VideoProcessingException When FFmpeg cannot read the part at all
     */
    public function measure(string $input, float $start, float $duration, AudioTreatmentSettings $settings): PartLoudness
    {
        if ($duration < (float) config('media-processing.audio_treatment.minimum_measurable_seconds', 3.0)) {
            return PartLoudness::untreated($duration, PartLoudness::TooShort);
        }

        $raw = null;
        $pauseRelative = null;

        if ($settings->needsWorkingLevel() || $settings->choosesDenoiseByPause()) {
            $raw = $this->firstPass($input, $start, $duration, $settings, $settings->levelIndependent());
            $rawReason = $this->unusableReason($raw, $settings);
            if ($raw === null || $rawReason !== null) {
                return PartLoudness::untreated($duration, $rawReason ?? PartLoudness::Silent, $raw);
            }
        }

        if ($raw !== null && $settings->choosesDenoiseByPause()) {
            $pause = $this->pauseLevel($input, $start, $duration, $settings);
            $pauseRelative = $pause === null ? null : $pause - $raw['input_i'];
            // An unreadable pause level gets no denoise rather than a guessed one.
            $settings = $settings->withDenoise($pauseRelative === null ? null : $settings->denoiseForPause($pauseRelative));
        }

        $workingGain = $raw !== null && $settings->needsWorkingLevel()
            ? max(-$settings->maxGainDb, min($settings->maxGainDb, AudioTreatmentSettings::WORKING_LUFS - $raw['input_i']))
            : null;

        $measured = $this->firstPass($input, $start, $duration, $settings, $settings->preprocessing($workingGain));
        $reason = $this->unusableReason($measured, $settings);

        return $reason === null
            ? new PartLoudness($duration, $measured, workingGainDb: $workingGain, raw: $raw, denoise: $settings->denoise, pauseRelative: $pauseRelative)
            : PartLoudness::untreated($duration, $reason, $raw);
    }

    /**
     * How loud the gaps between words are: the 10th percentile of 100 ms RMS windows,
     * in dBFS, through the same channel mix and fixed filters the part is measured
     * through. Digital silence (a gated source) reads −120. Null when unreadable.
     */
    public function pauseLevel(string $input, float $start, float $duration, AudioTreatmentSettings $settings): ?float
    {
        $process = new Process([
            (string) config('media-processing.ffmpeg.ffmpeg_path', '/usr/bin/ffmpeg'),
            '-hide_banner', '-nostats', '-loglevel', 'error',
            '-ss', $this->seconds($start), '-t', $this->seconds($duration), '-i', $input,
            '-map', '0:a:0',
            '-af', implode(',', [
                $this->trim($duration),
                ...$settings->levelIndependent(),
                'aresample=48000',
                'asetnsamples=n=4800:p=0',
                'astats=metadata=1:reset=1:measure_perchannel=none:measure_overall=RMS_level',
                'ametadata=print:key=lavfi.astats.Overall.RMS_level:file=/dev/stdout',
            ]),
            '-f', 'null', '-',
        ]);
        $process->setTimeout(1800);
        $process->run();

        if (! $process->isSuccessful() || ! preg_match_all('/RMS_level=(-?[\d.]+|-inf)/', $process->getOutput(), $matches)) {
            return null;
        }

        $levels = array_map(static fn (string $level): float => $level === '-inf' ? -120.0 : max(-120.0, (float) $level), $matches[1]);
        sort($levels);

        return $levels[(int) floor(0.1 * (count($levels) - 1))];
    }

    /**
     * The mode each part was actually normalised in, read from the encode's own
     * `loudnorm` reports. FFmpeg does not promise their order, so each is matched
     * to the part whose first-pass loudness it saw.
     *
     * @param  list<PartLoudness>  $parts
     * @return list<string|null>
     */
    public function modesFromEncode(string $stderr, array $parts): array
    {
        $reports = [];
        foreach ($this->jsonBlocks($stderr) as $block) {
            if (isset($block['normalization_type'], $block['input_i']) && is_numeric($block['input_i'])) {
                $reports[] = ['input_i' => (float) $block['input_i'], 'mode' => strtolower((string) $block['normalization_type'])];
            }
        }

        return array_map(function (PartLoudness $part) use (&$reports): ?string {
            $measured = $part->measured;
            if ($measured === null || $reports === []) {
                return null;
            }
            $distances = array_map(static fn (array $report): float => abs($report['input_i'] - $measured['input_i']), $reports);
            $nearest = array_keys($distances, min($distances))[0];
            if ($distances[$nearest] > 0.5) {
                return null;
            }
            $mode = $reports[$nearest]['mode'];
            array_splice($reports, $nearest, 1);

            return $mode;
        }, $parts);
    }

    /**
     * Measure every treated part of the encoded file and name each target it missed.
     *
     * A miss is a quality doubt about one output, not damage: the files and their
     * measurements are kept, and the caller holds what it would have published
     * (§6.3 review). Only a file FFmpeg cannot read at all is refused.
     *
     * @param  list<PartLoudness>  $parts
     * @param  float|null  $peakCeiling  The highest true peak (dBTP) a file may reach; null for the treatment ceiling plus its tolerance.
     *                                   A lossy file passes its own: the encoder overshoots its input at a transient.
     * @return list<array{integrated: float|null, true_peak: float|null, misses: list<string>}|null> per part; null where untreated
     *
     * @throws VideoProcessingException When the encoded file cannot be read
     */
    public function verify(string $encoded, array $parts, AudioTreatmentSettings $settings, bool $monoFile = false, ?float $peakCeiling = null): array
    {
        $tolerance = (float) config('media-processing.audio_treatment.loudness_tolerance_lu', 1.0);
        $peakTolerance = (float) config('media-processing.audio_treatment.true_peak_tolerance_db', 1.0);
        $peakLimit = $peakCeiling ?? $settings->truePeak + $peakTolerance;
        $results = [];
        $offset = 0.0;

        foreach ($parts as $part) {
            $start = $offset;
            $offset += $part->duration;

            if (! $part->isTreated()) {
                $results[] = null;

                continue;
            }

            $measured = $this->ebur128($encoded, $start, $part->duration, $monoFile);
            $misses = [];

            if ($measured['integrated'] === null || abs($measured['integrated'] - $settings->targetLufs) > $tolerance) {
                $misses[] = sprintf('integrated %s LUFS, target %.1f ±%.1f', $measured['integrated'] ?? 'unmeasurable', $settings->targetLufs, $tolerance);
            }

            if ($measured['true_peak'] === null || $measured['true_peak'] > $peakLimit) {
                $misses[] = sprintf('true peak %s dBTP, limit %.1f', $measured['true_peak'] ?? 'unmeasurable', $peakLimit);
            }

            $results[] = [...$measured, 'misses' => $misses];
        }

        return $results;
    }

    /**
     * @param  list<string>  $preprocessing
     * @return array{input_i: float, input_tp: float, input_lra: float, input_thresh: float, target_offset: float}|null null when FFmpeg reported nothing usable
     *
     * @throws VideoProcessingException
     */
    private function firstPass(string $input, float $start, float $duration, AudioTreatmentSettings $settings, array $preprocessing): ?array
    {
        $process = new Process([
            (string) config('media-processing.ffmpeg.ffmpeg_path', '/usr/bin/ffmpeg'),
            '-hide_banner', '-nostats',
            '-ss', $this->seconds($start), '-t', $this->seconds($duration), '-i', $input,
            '-map', '0:a:0',
            '-af', implode(',', [$this->trim($duration), ...$preprocessing, $this->loudnorm($settings).':print_format=json']),
            '-f', 'null', '-',
        ]);
        $process->setTimeout(1800);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new VideoProcessingException('FFmpeg could not measure the sound of '.basename($input).' at '.$this->seconds($start).' s: '.substr($process->getErrorOutput(), -500));
        }

        $block = $this->jsonBlocks($process->getErrorOutput())[0] ?? null;
        $keys = ['input_i', 'input_tp', 'input_lra', 'input_thresh', 'target_offset'];

        if (! is_array($block) || array_diff($keys, array_keys($block)) !== []) {
            return null;
        }

        $values = [];
        foreach ($keys as $key) {
            $value = $block[$key];
            $values[$key] = is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
        }

        return in_array(null, $values, true) ? null : $values;
    }

    /** @param  array{input_i: float, input_tp: float, input_lra: float, input_thresh: float, target_offset: float}|null  $measured */
    private function unusableReason(?array $measured, AudioTreatmentSettings $settings): ?string
    {
        if ($measured === null) {
            return PartLoudness::Silent;
        }

        if ($measured['input_i'] <= (float) config('media-processing.audio_treatment.silence_lufs', -70.0)) {
            return PartLoudness::Silent;
        }

        if ($settings->targetLufs - $measured['input_i'] > $settings->maxGainDb) {
            return PartLoudness::TooQuiet;
        }

        return null;
    }

    /**
     * @return array{integrated: float|null, true_peak: float|null}
     *
     * @throws VideoProcessingException When FFmpeg cannot read the file
     */
    private function ebur128(string $path, float $start, float $duration, bool $dualMono): array
    {
        $process = new Process([
            (string) config('media-processing.ffmpeg.ffmpeg_path', '/usr/bin/ffmpeg'),
            '-hide_banner', '-nostats',
            '-ss', $this->seconds($start), '-t', $this->seconds($duration), '-i', $path,
            '-map', '0:a:0',
            '-af', 'ebur128=peak=true'.($dualMono ? ':dualmono=true' : ''),
            '-f', 'null', '-',
        ]);
        $process->setTimeout(1800);
        $process->run();
        $stderr = $process->getErrorOutput();

        if (! $process->isSuccessful()) {
            throw new VideoProcessingException('FFmpeg could not measure the treated sound of '.basename($path).' at '.$this->seconds($start).' s: '.substr($stderr, -500));
        }

        $summary = substr($stderr, (int) strrpos($stderr, 'Summary:'));

        return [
            'integrated' => preg_match('/I:\s+(-?[\d.]+) LUFS/', $summary, $i) ? (float) $i[1] : null,
            'true_peak' => preg_match('/Peak:\s+(-?[\d.]+) dBFS/', $summary, $p) ? (float) $p[1] : null,
        ];
    }

    /** Mono speech is played on two speakers, so it is measured as such (EBU R128 dual mono). */
    private function loudnorm(AudioTreatmentSettings $settings): string
    {
        return sprintf('loudnorm=I=%.1f:TP=%.1f:LRA=%.1f', $settings->targetLufs, $settings->truePeak, $settings->lra)
            .($settings->isMono() ? ':dual_mono=true' : '');
    }

    private function trim(float $duration): string
    {
        return 'atrim=duration='.$this->seconds($duration).',asetpts=PTS-STARTPTS';
    }

    /** @return list<array<string, mixed>> */
    private function jsonBlocks(string $stderr): array
    {
        preg_match_all('/\{[^{}]*\}/s', $stderr, $matches);

        return array_values(array_filter(array_map(static fn (string $json): mixed => json_decode($json, true), $matches[0]), 'is_array'));
    }

    private function seconds(float $seconds): string
    {
        return rtrim(rtrim(sprintf('%.6F', $seconds), '0'), '.');
    }
}
