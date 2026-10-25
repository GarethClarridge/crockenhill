<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\AudioProfile;
use App\Services\Media\Audio\AudioTreatmentSettings;
use App\Services\Media\Audio\PartLoudness;
use App\Services\Media\Audio\SectionAudioTreatment;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SectionAudioSeekMeasurementTest extends TestCase
{
    #[Test]
    #[DataProvider('encodings')]
    public function verification_matches_decoding_before_trimming(string $codec, bool $pns, bool $transient): void
    {
        if (! is_executable('/usr/bin/ffmpeg')) {
            $this->markTestSkipped('FFmpeg is required.');
        }
        config(['media-processing.ffmpeg.ffmpeg_path' => '/usr/bin/ffmpeg']);
        Storage::fake('local');
        $path = Storage::disk('local')->path($codec === 'aac' ? 'noise.m4a' : 'noise.wav');
        $encode = ['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', $transient ? 'aevalsrc=0.03*sin(2*PI*997*t)+0.4*sin(2*PI*8000*t)*between(t\,2.5\,2.51):s=48000:d=6' : 'anoisesrc=color=white:seed=17:sample_rate=48000:duration=6:amplitude=0.1', '-c:a', $codec];
        if ($codec === 'aac') {
            array_push($encode, '-b:a', '32k', '-aac_pns', $pns ? '1' : '0');
        }
        $encode[] = $path;
        (new Process($encode))->setTimeout(60)->mustRun();
        $decoded = Storage::disk('local')->path('decoded.wav');
        (new Process(['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-i', $path, '-map', '0:a:0', '-c:a', 'pcm_f32le', $decoded]))->setTimeout(60)->mustRun();
        $measured = ['input_i' => -23.0, 'input_tp' => -4.0, 'input_lra' => 5.0, 'input_thresh' => -33.0, 'target_offset' => 0.0];
        $settings = AudioTreatmentSettings::for(AudioProfile::Music);
        foreach ([[0.0, 6.0], [1.137, 3.42]] as [$start, $duration]) {
            $parts = $start === 0.0 ? [] : [PartLoudness::untreated($start, PartLoudness::Unmeasurable)];
            $parts[] = new PartLoudness($duration, $measured);
            $results = (new SectionAudioTreatment)->verify($path, $parts, $settings);
            $actual = $results[array_key_last($results)];
            $reference = (new Process(['/usr/bin/ffmpeg', '-hide_banner', '-nostats', '-ss', (string) $start, '-t', (string) $duration, '-i', $decoded, '-map', '0:a:0', '-af', 'ebur128=peak=true:framelog=verbose', '-f', 'null', '-']))->setTimeout(60)->mustRun();
            $summary = substr($reference->getErrorOutput(), (int) strrpos($reference->getErrorOutput(), 'Summary:'));
            $this->assertSame(1, preg_match('/Peak:\s+(-?[\d.]+) dBFS/', $summary, $peak));
            $this->assertSame(1, preg_match('/I:\s+(-?[\d.]+) LUFS/', $summary, $integrated));
            $this->assertEqualsWithDelta((float) $peak[1], $actual['true_peak'], 0.1);
            $this->assertEqualsWithDelta((float) $integrated[1], $actual['integrated'], 0.1);
        }
    }

    /** @return array<string, array{string, bool, bool}> */
    public static function encodings(): array
    {
        return [
            'PCM noise' => ['pcm_s16le', false, false],
            'AAC noise without PNS' => ['aac', false, false],
            'AAC noise with PNS' => ['aac', true, false],
            'PCM transient' => ['pcm_s16le', false, true],
            'AAC transient' => ['aac', true, true],
        ];
    }
}
