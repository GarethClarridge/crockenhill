<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Contracts\ServiceTranscriptionInterface;
use App\Data\ChurchServiceTranscript;
use App\Models\MediaProcessingLog;
use App\Services\Media\Audio\ServiceAudioWindowExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RecoverCalibrationTranscriptsCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = storage_path('app/private/testing-calibration-recovery-'.bin2hex(random_bytes(6)));
        mkdir($this->workspace, 0700, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->workspace)) {
            foreach (glob($this->workspace.'/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($this->workspace);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_recovers_a_pathological_window_without_touching_the_banked_inputs(): void
    {
        $transcriptPath = $this->bankedTranscript();
        $audioPath = $this->bankedAudio();
        $transcriptHash = hash_file('sha256', $transcriptPath);
        $audioHash = hash_file('sha256', $audioPath);

        $this->stubRecoveryPipeline(ChurchServiceTranscript::fromCues([
            ['start' => 5.0, 'end' => 15.0, 'text' => 'Once in royal David’s city.'],
        ], 1200.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER));

        $reportPath = $this->workspace.'/report.json';

        $this->artisan('historic-import:recover-calibration-transcripts', [
            '--manifest' => $this->manifest($transcriptPath, $audioPath),
            '--output' => $reportPath,
        ])->assertExitCode(0);

        $this->assertSame($transcriptHash, hash_file('sha256', $transcriptPath), 'The banked transcript must not be rewritten.');
        $this->assertSame($audioHash, hash_file('sha256', $audioPath), 'The banked audio must not be rewritten.');

        $report = json_decode((string) file_get_contents($reportPath), true);
        $this->assertSame('recovered', $report['results'][0]['disposition']);
        $this->assertSame($transcriptHash, $report['results'][0]['input_sha256']);

        $recoveredPath = $report['results'][0]['recovered_path'];
        $this->assertFileExists($recoveredPath);
        $recovered = json_decode((string) file_get_contents($recoveredPath), true);
        $this->assertSame('Once in royal David’s city.', $recovered['cues'][0]['text']);
        @unlink($recoveredPath);
    }

    #[Test]
    public function it_creates_no_processing_records(): void
    {
        $this->stubRecoveryPipeline(ChurchServiceTranscript::fromCues([
            ['start' => 5.0, 'end' => 15.0, 'text' => 'Once in royal David’s city.'],
        ], 1200.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER));

        $reportPath = $this->workspace.'/report.json';
        $before = MediaProcessingLog::query()->count();

        $this->artisan('historic-import:recover-calibration-transcripts', [
            '--manifest' => $this->manifest($this->bankedTranscript(), $this->bankedAudio()),
            '--output' => $reportPath,
        ])->assertExitCode(0);

        $this->assertSame($before, MediaProcessingLog::query()->count(), 'Calibration recovery must not create processing records.');

        $report = json_decode((string) file_get_contents($reportPath), true);
        @unlink($report['results'][0]['recovered_path']);
    }

    #[Test]
    public function it_refuses_to_run_on_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('historic-import:recover-calibration-transcripts', [
            '--manifest' => $this->manifest($this->bankedTranscript(), $this->bankedAudio()),
            '--output' => $this->workspace.'/report.json',
        ])
            ->expectsOutputToContain('rehearsal')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_records_an_unrecovered_window_rather_than_failing_the_run(): void
    {
        $this->stubRecoveryPipeline($this->pathologicalTranscript());

        $reportPath = $this->workspace.'/report.json';

        $this->artisan('historic-import:recover-calibration-transcripts', [
            '--manifest' => $this->manifest($this->bankedTranscript(), $this->bankedAudio()),
            '--output' => $reportPath,
        ])->assertExitCode(0);

        $report = json_decode((string) file_get_contents($reportPath), true);
        $this->assertSame('unobservable', $report['results'][0]['disposition']);
        $this->assertSame('retranscription_failed', $report['results'][0]['unobservable_windows'][0]['reason']);
        @unlink($report['results'][0]['recovered_path']);
    }

    private function stubRecoveryPipeline(ChurchServiceTranscript $retry): void
    {
        $extractor = Mockery::mock(ServiceAudioWindowExtractor::class);
        $extractor->shouldReceive('extract')->andReturn($this->workspace.'/clip.mp3');
        $extractor->shouldReceive('delete');
        $this->app->instance(ServiceAudioWindowExtractor::class, $extractor);

        $transcription = Mockery::mock(ServiceTranscriptionInterface::class);
        $transcription->shouldReceive('transcribeService')->andReturn($retry);
        $this->app->instance(ServiceTranscriptionInterface::class, $transcription);
    }

    private function manifest(string $transcriptPath, string $audioPath): string
    {
        $path = $this->workspace.'/manifest-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($path, json_encode([
            'format' => 'crockenhill-calibration-transcript-recovery-manifest',
            'version' => 1,
            'identities' => [[
                'label' => '2024-12-22-evening',
                'transcript_path' => $transcriptPath,
                'audio_path' => $audioPath,
            ]],
        ]));

        return $path;
    }

    private function bankedTranscript(): string
    {
        $path = $this->workspace.'/banked.normalized.json';
        file_put_contents($path, json_encode($this->pathologicalTranscript()->toArray()));

        return $path;
    }

    private function bankedAudio(): string
    {
        $path = $this->workspace.'/banked.mp3';
        file_put_contents($path, 'not-real-audio');

        return $path;
    }

    private function pathologicalTranscript(): ChurchServiceTranscript
    {
        $cues = [];

        for ($index = 0; $index < 40; $index++) {
            $cues[] = ['start' => $index * 30.0, 'end' => ($index + 1) * 30.0, 'text' => 'Thank you.'];
        }

        $cues[] = ['start' => 1200.0, 'end' => 1210.0, 'text' => 'Closing prayer.'];

        return ChurchServiceTranscript::fromCues($cues, 1210.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER);
    }
}
