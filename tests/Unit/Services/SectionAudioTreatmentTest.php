<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\AudioProfile;
use App\Exceptions\VideoProcessingException;
use App\Services\Media\Audio\AudioCompressionService;
use App\Services\Media\Audio\AudioTreatmentSettings;
use App\Services\Media\Audio\PartLoudness;
use App\Services\Media\Audio\SectionAudioTreatment;
use App\Services\Media\Video\VideoExtractionService;
use App\Services\Processing\StorageAdapterHelper;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Real FFmpeg cuts of a recording shaped like canary 14's 1292: a reading recorded
 * about 20 LU quieter than the sermon it is joined to (VIDEO-BOUNDARY-CONSISTENCY §6.3).
 */
class SectionAudioTreatmentTest extends TestCase
{
    /** Quiet "reading" 0–15 s with a knock at 7 s, louder "sermon" 15–40 s, digital silence 40–46 s. */
    private const array READING = ['start_time' => 0.5, 'end_time' => 14.5];

    private const array SERMON = ['start_time' => 16.0, 'end_time' => 39.0];

    private const array SILENCE = ['start_time' => 40.5, 'end_time' => 45.5];

    private VideoExtractionService $service;

    private string $sourceDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_executable('/usr/bin/ffmpeg') || ! is_executable('/usr/bin/ffprobe')) {
            $this->markTestSkipped('ffmpeg and ffprobe are required to measure real cuts.');
        }

        Storage::fake('local');

        Config::set('media-processing.ffmpeg.ffmpeg_path', '/usr/bin/ffmpeg');
        Config::set('media-processing.ffmpeg.ffprobe_path', '/usr/bin/ffprobe');
        Config::set('media-processing.storage.temp_disk', 'local');
        Config::set('media-processing.video_extraction.reencode_preset', 'ultrafast');

        $this->sourceDirectory = storage_path('framework/testing/audio-treatment-sources');
        if (! is_dir($this->sourceDirectory)) {
            mkdir($this->sourceDirectory, 0755, true);
        }

        $this->service = new VideoExtractionService(app(AudioCompressionService::class), app(StorageAdapterHelper::class));
    }

    #[Test]
    public function a_quiet_reading_and_a_louder_sermon_are_each_brought_to_the_target(): void
    {
        $source = $this->recording();
        $this->assertGreaterThan(15.0, $this->integrated($source, 16.0, 23.0) - $this->integrated($source, 0.5, 14.0), 'The fixture must reproduce a 1292-sized spread.');

        $media = $this->service->extractMedia($source, [self::READING, self::SERMON], AudioProfile::Speech, withPublicAudio: true);
        $video = Storage::disk('local')->path($media->videoPath);

        $this->assertEqualsWithDelta(-16.0, $this->integrated($video, 0.0, 14.0), 1.0);
        $this->assertEqualsWithDelta(-16.0, $this->integrated($video, 14.0, 23.0), 1.0);
        $this->assertSame(0, $media->untreatedParts());
        $this->assertSame(['dynamic', 'linear'], array_column($media->audio['parts'], 'mode'), 'The reading needs more gain than its peaks allow linearly.');
        $this->assertSame(array_column($media->audio['parts'], 'expected_mode'), array_column($media->audio['parts'], 'mode'));
    }

    #[Test]
    public function the_public_mp3_is_one_channel_at_listening_quality_and_matches_the_video(): void
    {
        $media = $this->service->extractMedia($this->recording(), [self::READING, self::SERMON], AudioProfile::Speech, withPublicAudio: true);
        $mp3 = Storage::disk('local')->path((string) $media->audioPath);
        $video = Storage::disk('local')->path($media->videoPath);
        $stream = $this->probe($mp3);

        $this->assertSame('mp3', $stream['codec_name']);
        $this->assertSame(1, $stream['channels']);
        $this->assertSame('48000', $stream['sample_rate']);
        $this->assertEqualsWithDelta($this->duration($video), $this->duration($mp3), 0.1);
        // One channel played on two speakers: measured as such, it is as loud as the video.
        $this->assertEqualsWithDelta(-16.0, $this->integrated($mp3, 0.0, 14.0, dualMono: true), 1.0);
        $this->assertEqualsWithDelta(-16.0, $this->integrated($mp3, 14.0, 23.0, dualMono: true), 1.0);
        $this->assertNotNull($media->audio['parts'][0]['public_audio']);
    }

    #[Test]
    public function treatments_that_change_the_level_are_inside_the_measurement(): void
    {
        // dynaudnorm raises the quiet stretches: normalising with statistics taken before it
        // (what the transcription chain does) overshoots. Measuring after it lands on target.
        Config::set('media-processing.audio_treatment.speech.dynamics', 'dynaudnorm=f=200:g=11:m=10');
        Config::set('media-processing.audio_treatment.speech.denoise', 'nr=10:nf=-50:tn=1');
        Config::set('media-processing.audio_treatment.speech.high_pass_hz', 80);

        $media = $this->service->extractMedia($this->recording(), [self::READING, self::SERMON], AudioProfile::Speech);
        $video = Storage::disk('local')->path($media->videoPath);

        $this->assertEqualsWithDelta(-16.0, $this->integrated($video, 0.0, 14.0), 1.0);
        $this->assertEqualsWithDelta(-16.0, $this->integrated($video, 14.0, 23.0), 1.0);
        $reading = $media->audio['parts'][0];
        $this->assertNotNull($reading['raw']);
        $this->assertEqualsWithDelta(AudioTreatmentSettings::WORKING_LUFS - $reading['raw']['input_i'], $reading['working_gain_db'], 0.01);
        $this->assertGreaterThan(15.0, $reading['working_gain_db']);
    }

    #[Test]
    public function a_silent_part_is_left_as_recorded_and_reported_not_raised(): void
    {
        $media = $this->service->extractMedia($this->recording(), [self::SERMON, self::SILENCE], AudioProfile::Speech);
        $video = Storage::disk('local')->path($media->videoPath);

        $this->assertSame(PartLoudness::Silent, $media->audio['parts'][1]['untreated_reason']);
        $this->assertNull($media->audio['parts'][1]['measured']);
        $this->assertNull($media->audio['parts'][1]['video']);
        $this->assertSame(1, $media->untreatedParts());
        $this->assertEqualsWithDelta(-16.0, $this->integrated($video, 0.0, 23.0), 1.0);
        $this->assertLessThan(-80.0, $this->rms($video, 23.5, 4.0));
    }

    #[Test]
    public function a_part_too_short_to_measure_is_left_as_recorded_and_reported(): void
    {
        $media = $this->service->extractMedia($this->recording(), [['start_time' => 5.0, 'end_time' => 7.0], self::SERMON], AudioProfile::Speech);

        $this->assertSame(PartLoudness::TooShort, $media->audio['parts'][0]['untreated_reason']);
        $this->assertNotNull($media->audio['parts'][1]['measured']);
    }

    #[Test]
    public function a_part_needing_more_gain_than_allowed_is_left_as_recorded(): void
    {
        Config::set('media-processing.audio_treatment.speech.max_gain_db', 20.0);

        $media = $this->service->extractMedia($this->recording(), [self::READING, self::SERMON], AudioProfile::Speech);

        $this->assertSame(PartLoudness::TooQuiet, $media->audio['parts'][0]['untreated_reason']);
        $this->assertNull($media->audio['parts'][1]['untreated_reason']);
    }

    #[Test]
    public function music_keeps_its_two_channels_and_its_fade_survives_normalisation(): void
    {
        $media = $this->service->extractMedia($this->recording(), [['start_time' => 16.0, 'end_time' => 36.0]], AudioProfile::Music, audioFadeOut: 3.0);
        $video = Storage::disk('local')->path($media->videoPath);

        $this->assertEqualsWithDelta(-16.0, $this->integrated($video, 0.0, 16.0), 1.5);
        $this->assertGreaterThan(-60.0, $this->rms($video, 2.0, 10.0, 'pan=mono|c0=0.5*c0-0.5*c1'), 'The channels must stay different.');
        $this->assertLessThan($this->rms($video, 12.0, 4.0) - 15.0, $this->rms($video, 19.6, 0.3), 'The fade must still close the song.');
        $this->assertSame(2, $this->probe($video)['channels']);
    }

    #[Test]
    public function an_untreated_cut_leaves_the_sound_as_recorded(): void
    {
        $media = $this->service->extractMedia($this->recording(), [self::READING, self::SERMON], null);
        $video = Storage::disk('local')->path($media->videoPath);

        $this->assertNull($media->audio);
        $this->assertGreaterThan(15.0, $this->integrated($video, 14.0, 23.0) - $this->integrated($video, 0.0, 14.0));
    }

    #[Test]
    public function verification_names_each_target_a_part_missed(): void
    {
        $settings = AudioTreatmentSettings::for(AudioProfile::Speech);
        $untouched = $this->service->extractMedia($this->recording(), [self::READING, self::SERMON], null);
        $claimed = [
            new PartLoudness(14.0, ['input_i' => -47.0, 'input_tp' => -30.0, 'input_lra' => 3.0, 'input_thresh' => -57.0, 'target_offset' => 0.0]),
            new PartLoudness(23.0, ['input_i' => -27.0, 'input_tp' => -10.0, 'input_lra' => 3.0, 'input_thresh' => -37.0, 'target_offset' => 0.0]),
        ];

        $results = app(SectionAudioTreatment::class)->verify(Storage::disk('local')->path($untouched->videoPath), $claimed, $settings);

        $this->assertMatchesRegularExpression('/^integrated -\d+(\.\d)? LUFS, target -16\.0 ±1\.0$/', $results[0]['misses'][0]);
        $this->assertLessThan(-30.0, $results[0]['integrated'], 'The measurement is kept for the reviewer.');
    }

    #[Test]
    public function verification_refuses_a_file_it_cannot_read(): void
    {
        $settings = AudioTreatmentSettings::for(AudioProfile::Speech);
        $part = new PartLoudness(5.0, ['input_i' => -20.0, 'input_tp' => -5.0, 'input_lra' => 3.0, 'input_thresh' => -30.0, 'target_offset' => 0.0]);
        Storage::disk('local')->put('temp/not-media.mp4', 'not media');

        $this->expectException(VideoProcessingException::class);

        app(SectionAudioTreatment::class)->verify(Storage::disk('local')->path('temp/not-media.mp4'), [$part], $settings);
    }

    #[Test]
    public function a_cut_that_misses_its_target_keeps_its_files_and_reports_the_miss(): void
    {
        // A tolerance nothing can meet: every treated part misses.
        Config::set('media-processing.audio_treatment.loudness_tolerance_lu', -1.0);

        $media = $this->service->extractMedia($this->recording(), [self::READING, self::SERMON], AudioProfile::Speech, withPublicAudio: true);

        Storage::disk('local')->assertExists($media->videoPath);
        Storage::disk('local')->assertExists((string) $media->audioPath);
        $this->assertCount(4, $media->loudnessMisses(), 'Two parts, each in the video and the MP3.');
        $this->assertStringStartsWith('part 1 video integrated', $media->loudnessMisses()[0]);
        $this->assertEqualsWithDelta(-16.0, $media->audio['parts'][1]['video']['integrated'], 1.0);
    }

    #[Test]
    public function a_run_override_changes_the_settings_and_is_reported(): void
    {
        $media = $this->service->extractMedia($this->recording(), [self::SERMON], AudioProfile::Speech, treatmentOverrides: ['hum_hz' => 50, 'target_lufs' => -18.0]);
        $video = Storage::disk('local')->path($media->videoPath);

        $this->assertSame(50.0, $media->audio['settings']['hum_hz']);
        $this->assertEqualsWithDelta(-18.0, $this->integrated($video, 0.0, 23.0), 1.0);
    }

    #[Test]
    public function each_part_is_denoised_as_strongly_as_its_pauses_need(): void
    {
        $source = $this->gatedThenHissy();
        $media = $this->service->extractMedia($source, [['start_time' => 0.0, 'end_time' => 12.0], ['start_time' => 12.0, 'end_time' => 24.0]], AudioProfile::Speech);
        [$gated, $hissy] = $media->audio['parts'];

        $this->assertLessThan(-60.0, $gated['pause_relative']);
        $this->assertNull($gated['denoise'], 'Silence between words has nothing to remove.');
        $this->assertNull($gated['working_gain_db']);
        $this->assertGreaterThan(-30.0, $hissy['pause_relative']);
        $this->assertSame('nr=10:nf=-25', $hissy['denoise']);
        $this->assertNotNull($hissy['working_gain_db']);
        $video = Storage::disk('local')->path($media->videoPath);
        $this->assertEqualsWithDelta(-16.0, $this->integrated($video, 12.0, 12.0), 1.0);
        // The gap at 12.6–12.9 s is hiss alone; relative to the part's loudness it must come out quieter than it went in.
        $hissIn = $this->rms($source, 12.6, 0.3) - $this->integrated($source, 12.0, 12.0);
        $hissOut = $this->rms($video, 12.6, 0.3) - $this->integrated($video, 12.0, 12.0);
        $this->assertLessThan($hissIn - 3.0, $hissOut);
    }

    #[Test]
    public function a_recording_can_turn_denoising_off_or_fix_it(): void
    {
        $source = $this->gatedThenHissy();
        $span = [['start_time' => 12.0, 'end_time' => 24.0]];

        $off = $this->service->extractMedia($source, $span, AudioProfile::Speech, treatmentOverrides: ['denoise' => 'off']);
        $fixed = $this->service->extractMedia($source, $span, AudioProfile::Speech, treatmentOverrides: ['denoise' => 'nr=6:nf=-40']);

        $this->assertNull($off->audio['parts'][0]['denoise']);
        $this->assertNull($off->audio['parts'][0]['pause_relative']);
        $this->assertSame('nr=6:nf=-40', $fixed->audio['parts'][0]['denoise']);
    }

    /** 0–12 s: syllables with digitally silent gaps (a gated source); 12–24 s: the same with hiss in the gaps. */
    private function gatedThenHissy(): string
    {
        $path = "{$this->sourceDirectory}/gated-then-hissy.mkv";

        if (is_file($path)) {
            return $path;
        }

        $partial = $path.'.part-'.getmypid().'.mkv';
        $speech = 'if(lt(mod(t\\,1)\\,0.5)\\,0.2*sin(2*PI*220*t)*(0.6+0.4*sin(2*PI*5*t))\\,0)';
        $hiss = 'if(gte(t\\,12)\\,0.02*(random(0)-0.5)\\,0)';

        (new Process([
            '/usr/bin/ffmpeg', '-v', 'error', '-y',
            '-f', 'lavfi', '-i', 'color=black:size=160x120:rate=25:duration=24',
            '-f', 'lavfi', '-i', "aevalsrc={$speech}+{$hiss}|{$speech}+{$hiss}:s=48000:d=24",
            '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-g', '25',
            '-c:a', 'pcm_s16le',
            $partial,
        ]))->setTimeout(180)->mustRun();

        rename($partial, $path);

        return $path;
    }

    /**
     * A shared 46 s recording: different speech-like sounds left and right so a
     * music cut can show it kept both channels.
     */
    private function recording(): string
    {
        $path = "{$this->sourceDirectory}/reading-knock-and-sermon.mp4";

        if (is_file($path)) {
            return $path;
        }

        $partial = $path.'.part-'.getmypid().'.mp4';
        $level = 'if(lt(t\\,15)\\,0.004\\,if(lt(t\\,40)\\,0.05\\,0))';
        $syllables = '(0.55+0.45*sin(2*PI*3.3*t))';
        // A knock during the reading: its peak, not its speech, limits how far it can be raised linearly.
        $knock = '+if(between(t\\,7\\,7.003)\\,0.3\\,0)';

        (new Process([
            '/usr/bin/ffmpeg', '-v', 'error', '-y',
            '-f', 'lavfi', '-i', 'color=black:size=160x120:rate=25:duration=46',
            '-f', 'lavfi', '-i', "aevalsrc={$level}*{$syllables}*(sin(2*PI*220*t)+0.5*sin(2*PI*660*t)){$knock}|{$level}*{$syllables}*(sin(2*PI*330*t)+0.4*sin(2*PI*1500*t)){$knock}:s=48000:d=46",
            '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-g', '25',
            '-c:a', 'aac', '-b:a', '192k',
            $partial,
        ]))->setTimeout(180)->mustRun();

        rename($partial, $path);

        return $path;
    }

    private function integrated(string $path, float $start, float $duration, bool $dualMono = false): float
    {
        $process = new Process(['/usr/bin/ffmpeg', '-hide_banner', '-nostats', '-ss', (string) $start, '-t', (string) $duration, '-i', $path,
            '-map', '0:a:0', '-af', 'ebur128'.($dualMono ? '=dualmono=true' : ''), '-f', 'null', '-']);
        $process->mustRun();
        $stderr = $process->getErrorOutput();
        $this->assertSame(1, preg_match('/I:\s+(-?[\d.]+) LUFS/', substr($stderr, (int) strrpos($stderr, 'Summary:')), $match));

        return (float) $match[1];
    }

    private function rms(string $path, float $start, float $duration, string $mix = 'anull'): float
    {
        $process = new Process(['/usr/bin/ffmpeg', '-hide_banner', '-nostats', '-ss', (string) $start, '-t', (string) $duration, '-i', $path,
            '-map', '0:a:0', '-af', "{$mix},astats=measure_perchannel=none", '-f', 'null', '-']);
        $process->mustRun();
        preg_match_all('/RMS level dB:\s+(-?[\d.]+|-inf)/', $process->getErrorOutput(), $matches);
        $last = end($matches[1]);

        return $last === '-inf' || $last === false ? -200.0 : (float) $last;
    }

    /** @return array{codec_name: string, channels: int, sample_rate: string} */
    private function probe(string $path): array
    {
        $process = new Process(['/usr/bin/ffprobe', '-v', 'error', '-select_streams', 'a:0', '-show_entries', 'stream=codec_name,channels,sample_rate', '-of', 'json', $path]);
        $process->mustRun();

        return json_decode($process->getOutput(), true)['streams'][0];
    }

    private function duration(string $path): float
    {
        $process = new Process(['/usr/bin/ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $path]);
        $process->mustRun();

        return (float) trim($process->getOutput());
    }
}
