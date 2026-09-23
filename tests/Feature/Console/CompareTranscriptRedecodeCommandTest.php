<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Data\ChurchServiceTranscript;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\Media\Audio\ServiceTranscriptRedecoder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CompareTranscriptRedecodeCommandTest extends TestCase
{
    use DatabaseTransactions;

    private const StoredTranscript = 'service-transcripts/2020-01-05/morning-stored.json';

    private string $inputDir;

    private string $againstDir;

    private string $output;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Config::set('media-processing.storage.transcript_disk', 'local');

        $root = sys_get_temp_dir().'/redecode-compare-'.bin2hex(random_bytes(4));
        $this->inputDir = "{$root}/new";
        $this->againstDir = "{$root}/again";
        $this->output = "{$root}/report.json";
        mkdir($this->inputDir, 0700, true);
        mkdir($this->againstDir, 0700, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->inputDir));

        parent::tearDown();
    }

    #[Test]
    public function it_scores_each_window_against_the_stored_transcript_with_both_screens_overlaid(): void
    {
        $stored = $this->transcript(['Good morning and welcome.', 'Let us pray pray pray pray pray pray pray pray.']);
        $run = $this->runWithStoredTranscript($stored);
        $this->writeArtifact($this->inputDir, $run, $this->transcript(['Good morning and welcome.', 'Let us pray together now.']), $stored, [
            ['start' => 30.0, 'end' => 60.0, 'reason' => 'repeated_phrase', 'words' => 10, 'words_per_minute' => 20.0],
        ]);

        $this->compare()->assertSuccessful();

        $report = $this->report();
        $windows = $report['runs'][0]['windows'];

        self::assertSame('0600', substr(sprintf('%o', fileperms($this->output)), -4));
        self::assertSame('stored', $report['mode']);
        self::assertSame([], $report['unassessable']);
        self::assertCount(2, $windows);
        self::assertSame(0.0, $windows[0]['token_distance']);
        self::assertGreaterThan(0.0, $windows[1]['token_distance']);
        self::assertGreaterThan($windows[1]['new_repetition'], $windows[1]['stored_repetition']);
        self::assertFalse($windows[0]['stored_screen_overlap']);
        self::assertTrue($windows[1]['stored_screen_overlap']);
        self::assertFalse($windows[1]['new_screen_overlap']);
        self::assertSame(1, $report['runs'][0]['summary']['differing_windows']);
        self::assertSame(hash_file('sha256', "{$this->inputDir}/run-{$run->id}.json"), $report['inputs'][0]['artifact_sha256']);
    }

    #[Test]
    public function it_keeps_an_unscreened_stored_transcript_unknown_rather_than_clear(): void
    {
        $stored = $this->transcript(['Good morning.', 'Amen.']);
        $run = $this->runWithStoredTranscript($stored);
        $this->writeArtifact($this->inputDir, $run, $stored, $stored, null);

        $this->compare()->assertSuccessful();

        self::assertNull($this->report()['runs'][0]['windows'][0]['stored_screen_overlap']);
    }

    #[Test]
    public function it_counts_a_stored_transcript_changed_after_the_decode_as_unassessable(): void
    {
        $stored = $this->transcript(['Good morning.', 'Amen.']);
        $run = $this->runWithStoredTranscript($stored);
        $this->writeArtifact($this->inputDir, $run, $stored, $this->transcript(['Something else.', 'Amen.']), []);

        $this->compare()->assertSuccessful();

        self::assertSame([['run_id' => $run->id, 'reason' => 'stored transcript changed after the decode']], $this->report()['unassessable']);
        self::assertSame([], $this->report()['runs']);
    }

    /**
     * 1007 and 38 others: the stored decode's last cue is stamped out to the end of its
     * padded 30 s window, and the stored duration follows it. The source hash already
     * proves the audio is the same, so the pair is compared over the shared span.
     */
    #[Test]
    public function it_compares_over_the_shared_span_when_a_final_cue_overran_the_audio(): void
    {
        $stored = ChurchServiceTranscript::fromCues(
            [['start' => 1.0, 'end' => 29.0, 'text' => 'Good morning.'], ['start' => 31.0, 'end' => 84.0, 'text' => 'Amen.']],
            84.0,
            ChurchServiceTranscript::SOURCE_LOCAL_WHISPER,
        );
        $run = $this->runWithStoredTranscript($stored);
        $this->writeArtifact($this->inputDir, $run, $this->transcript(['Good morning.', 'Amen.'], duration: 60.0), $stored, []);

        $this->compare()->assertSuccessful();

        $report = $this->report();
        self::assertSame([], $report['unassessable']);
        self::assertCount(2, $report['runs'][0]['windows']);
        self::assertSame(24.0, $report['runs'][0]['duration_overrun']);
        self::assertSame(0, $report['runs'][0]['summary']['differing_windows']);
    }

    #[Test]
    public function it_counts_durations_a_whole_window_apart_as_unassessable(): void
    {
        $stored = $this->transcript(['Good morning.', 'Amen.']);
        $run = $this->runWithStoredTranscript($stored);
        $this->writeArtifact($this->inputDir, $run, $this->transcript(['Good morning.'], duration: 30.0), $stored, []);

        $this->compare()->assertSuccessful();

        self::assertStringContainsString('source durations differ', $this->report()['unassessable'][0]['reason']);
    }

    #[Test]
    public function it_drops_prompt_echoes_from_the_new_decode_as_the_pipeline_does(): void
    {
        $prompt = (string) config('media-processing.transcription.prompts.full_service');
        $stored = $this->transcript(['Good morning.', 'Amen.']);
        $run = $this->runWithStoredTranscript($stored);
        $this->writeArtifact($this->inputDir, $run, $this->transcript([$prompt, 'Amen.']), $stored, []);

        $this->compare()->assertSuccessful();

        $window = $this->report()['runs'][0]['windows'][0];
        self::assertSame(0, $window['new_tokens']);
    }

    #[Test]
    public function it_measures_the_noise_floor_between_two_decodes_of_the_same_run(): void
    {
        $stored = $this->transcript(['Stored text.', 'Amen.']);
        $run = $this->runWithStoredTranscript($stored);
        $decode = $this->transcript(['Good morning.', 'Amen.']);
        $this->writeArtifact($this->inputDir, $run, $decode, $stored, []);
        $this->writeArtifact($this->againstDir, $run, $decode, $stored, []);

        $this->compare(['--against-dir' => $this->againstDir])->assertSuccessful();

        $report = $this->report();
        self::assertSame('redecode', $report['mode']);
        self::assertSame(0, $report['runs'][0]['summary']['differing_windows']);
        self::assertTrue($report['runs'][0]['compressed_audio_match']);
        self::assertNull($report['runs'][0]['windows'][0]['stored_screen_overlap']);
    }

    #[Test]
    public function it_refuses_a_noise_floor_between_decodes_sent_different_options(): void
    {
        $stored = $this->transcript(['Good morning.', 'Amen.']);
        $run = $this->runWithStoredTranscript($stored);
        $this->writeArtifact($this->inputDir, $run, $stored, $stored, []);
        $this->writeArtifact($this->againstDir, $run, $stored, $stored, [], ['max_context' => '-1']);

        $this->compare(['--against-dir' => $this->againstDir])->assertSuccessful();

        self::assertSame('the two decodes sent different options', $this->report()['unassessable'][0]['reason']);
    }

    /**
     * The C2 residue sits in singing and mumbled speech, so the listening rule has to
     * know what each window holds without anyone sorting windows by hand.
     */
    #[Test]
    public function it_labels_each_window_with_the_section_it_falls_in_and_its_sustained_sound(): void
    {
        Config::set('media-processing.segmentation.adaptive_thresholds.enabled', false);
        Config::set('media-processing.segmentation.rms_threshold', -45.0);

        $stored = $this->transcript(['Good morning.', 'Amen.', 'Hallelujah.'], duration: 90.0);
        $run = $this->runWithStoredTranscript($stored, ['rms_log_path' => 'service-transcripts/2020-01-05/run.rms.json']);
        Storage::disk('local')->put('service-transcripts/2020-01-05/run.rms.json', $this->rmsLog(90, singingFrom: 30));
        ServiceSection::factory()->create(['media_processing_log_id' => $run->id, 'section_type' => ServiceSectionType::Prayer, 'start_time' => 0.0, 'end_time' => 28.0]);
        ServiceSection::factory()->create(['media_processing_log_id' => $run->id, 'section_type' => ServiceSectionType::Song, 'start_time' => 28.0, 'end_time' => 60.0]);
        $this->writeArtifact($this->inputDir, $run, $stored, $stored, []);

        $this->compare()->assertSuccessful();

        $windows = $this->report()['runs'][0]['windows'];
        self::assertSame(['prayer', 'song', null], array_column($windows, 'section_type'));
        self::assertLessThan(0.5, $windows[0]['sustained_share']);
        self::assertGreaterThan(0.5, $windows[2]['sustained_share']);
    }

    #[Test]
    public function it_leaves_sustained_sound_unknown_when_the_run_has_no_rms_log(): void
    {
        $stored = $this->transcript(['Good morning.', 'Amen.']);
        $run = $this->runWithStoredTranscript($stored);
        $this->writeArtifact($this->inputDir, $run, $stored, $stored, []);

        $this->compare()->assertSuccessful();

        $window = $this->report()['runs'][0]['windows'][0];
        self::assertNull($window['sustained_share']);
        self::assertNull($window['section_type']);
    }

    #[Test]
    public function it_carries_each_runs_stratum_and_counts_them(): void
    {
        $stored = $this->transcript(['Good morning.', 'Amen.']);
        $run = $this->runWithStoredTranscript($stored);
        $this->writeArtifact($this->inputDir, $run, $stored, $stored, [], stratum: 'recovered');

        $this->compare()->assertSuccessful();

        self::assertSame('recovered', $this->report()['runs'][0]['stratum']);
        self::assertSame(['recovered' => 1], $this->report()['strata']);
    }

    #[Test]
    public function it_refuses_to_overwrite_an_existing_report(): void
    {
        file_put_contents($this->output, 'earlier');

        $this->compare()->expectsOutputToContain('Refusing to overwrite')->assertFailed();

        self::assertSame('earlier', file_get_contents($this->output));
    }

    #[Test]
    public function it_fails_when_there_are_no_artifacts(): void
    {
        $this->compare()->expectsOutputToContain('No re-decode artifacts')->assertFailed();
    }

    /** @param array<string, mixed> $options */
    private function compare(array $options = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('service:compare-transcript-redecode', [
            '--input-dir' => $this->inputDir,
            '--output' => $this->output,
            ...$options,
        ]);
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        return json_decode((string) file_get_contents($this->output), true);
    }

    /** @param list<string> $windowTexts One cue per 30-second window */
    private function transcript(array $windowTexts, float $duration = 60.0): ChurchServiceTranscript
    {
        $cues = [];

        foreach ($windowTexts as $index => $text) {
            $cues[] = ['start' => $index * 30.0 + 1.0, 'end' => $index * 30.0 + 29.0, 'text' => $text];
        }

        return ChurchServiceTranscript::fromCues($cues, $duration, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER);
    }

    /** @param array<string, mixed> $attributes */
    private function runWithStoredTranscript(ChurchServiceTranscript $stored, array $attributes = []): MediaProcessingLog
    {
        Storage::disk('local')->put(self::StoredTranscript, json_encode($stored->toArray(), JSON_THROW_ON_ERROR));

        return MediaProcessingLog::factory()->livestream()->create([
            'processing_metadata' => ['service_transcript_path' => self::StoredTranscript],
            'rms_log_path' => null,
            ...$attributes,
        ]);
    }

    /** An astats log sampled every 0.1 s: paused speech, then unbroken singing. */
    private function rmsLog(int $seconds, int $singingFrom): string
    {
        $lines = [];

        for ($tenth = 0; $tenth < $seconds * 10; $tenth++) {
            $time = $tenth / 10;
            $level = $time >= $singingFrom ? -18.0 : (fmod($time, 3.0) < 2.5 ? -25.0 : -60.0);
            $lines[] = sprintf('frame:%d pts:%d pts_time:%.1f', $tenth, $tenth * 800, $time);
            $lines[] = sprintf('lavfi.astats.Overall.RMS_level=%.1f', $level);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<array<string, mixed>>|null  $storedBlocks
     * @param  array<string, string>  $requestOverrides
     */
    private function writeArtifact(
        string $dir,
        MediaProcessingLog $run,
        ChurchServiceTranscript $new,
        ChurchServiceTranscript $storedAtDecode,
        ?array $storedBlocks,
        array $requestOverrides = [],
        string $stratum = 'original',
    ): void {
        file_put_contents("{$dir}/run-{$run->id}.json", json_encode([
            'schema' => ServiceTranscriptRedecoder::SCHEMA,
            'run_id' => $run->id,
            'source' => ['path' => 'temp/source.webm', 'sha256' => str_repeat('a', 64)],
            'stored_transcript' => [
                'path' => self::StoredTranscript,
                'sha256' => ServiceTranscriptRedecoder::transcriptHash($storedAtDecode),
                'duration' => $storedAtDecode->duration,
                'suspect_blocks' => $storedBlocks,
                'stratum' => $stratum,
            ],
            'compressed_audio' => ['sha256' => str_repeat('b', 64), 'bytes' => 16],
            'decode' => ['request' => ['max_context' => '0', ...$requestOverrides]],
            'new_transcript' => $new->toArray(),
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
