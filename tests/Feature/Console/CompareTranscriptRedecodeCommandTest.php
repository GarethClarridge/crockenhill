<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Data\ChurchServiceTranscript;
use App\Models\MediaProcessingLog;
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

    #[Test]
    public function it_counts_differing_source_durations_as_unassessable(): void
    {
        $stored = $this->transcript(['Good morning.', 'Amen.']);
        $run = $this->runWithStoredTranscript($stored);
        $this->writeArtifact($this->inputDir, $run, $this->transcript(['Good morning.', 'Amen.'], duration: 61.0), $stored, []);

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

    private function runWithStoredTranscript(ChurchServiceTranscript $stored): MediaProcessingLog
    {
        Storage::disk('local')->put(self::StoredTranscript, json_encode($stored->toArray(), JSON_THROW_ON_ERROR));

        return MediaProcessingLog::factory()->livestream()->create([
            'processing_metadata' => ['service_transcript_path' => self::StoredTranscript],
        ]);
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
            ],
            'compressed_audio' => ['sha256' => str_repeat('b', 64), 'bytes' => 16],
            'decode' => ['request' => ['max_context' => '0', ...$requestOverrides]],
            'new_transcript' => $new->toArray(),
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
