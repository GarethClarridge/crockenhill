<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Contracts\ServiceTranscriptionInterface;
use App\Data\ChurchServiceTranscript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeasureTranscriptionPrimingCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = storage_path('app/private/testing-priming-measurement-'.bin2hex(random_bytes(6)));
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
    public function it_draws_both_arms_for_every_identity_and_reports_the_pathology_difference(): void
    {
        $audioPath = $this->bankedAudio();
        $audioHash = hash_file('sha256', $audioPath);

        // The production prompt is passed as null and no priming as ''. The
        // stub keys off that distinction alone, so a command that forgot to
        // vary the prompt would produce two identical arms and fail below.
        $this->stubTranscriptionByPrompt(
            primed: $this->loopedTranscript(),
            unprimed: $this->cleanTranscript(),
        );

        $reportPath = $this->workspace.'/report.json';

        $this->artisan('historic-import:measure-transcription-priming', [
            '--manifest' => $this->manifest($audioPath),
            '--output' => $reportPath,
            '--draws' => 2,
        ])->assertExitCode(0);

        $this->assertSame($audioHash, hash_file('sha256', $audioPath), 'Banked audio must not be rewritten.');

        $report = json_decode((string) file_get_contents($reportPath), true);

        $this->assertCount(4, $report['runs'], 'One identity, two arms, two draws.');
        $this->assertSame(2, $report['draws_per_arm']);
        $this->assertFalse($report['processing_records_created']);

        $this->assertSame(2, $report['summary']['by_arm']['primed']['draws_with_detector_window']);
        $this->assertSame(0, $report['summary']['by_arm']['unprimed']['draws_with_detector_window']);
        $this->assertGreaterThan(
            $report['summary']['by_arm']['unprimed']['mean_repeated_cue_share'],
            $report['summary']['by_arm']['primed']['mean_repeated_cue_share'],
        );

        foreach ($report['runs'] as $run) {
            $this->assertSame($audioHash, $run['audio_sha256']);
            $this->assertFileExists($run['transcript_path']);
            $this->assertSame(hash_file('sha256', $run['transcript_path']), $run['transcript_sha256']);
        }
    }

    #[Test]
    public function it_refuses_a_manifest_whose_banked_audio_is_missing(): void
    {
        $manifestPath = $this->workspace.'/manifest.json';
        file_put_contents($manifestPath, json_encode([
            'format' => 'crockenhill-transcription-priming-manifest',
            'version' => 1,
            'identities' => [['label' => '2024-01-14-morning', 'audio_path' => $this->workspace.'/absent.mp3']],
        ]));

        $this->artisan('historic-import:measure-transcription-priming', [
            '--manifest' => $manifestPath,
            '--output' => $this->workspace.'/report.json',
        ])->assertExitCode(1);

        $this->assertFileDoesNotExist($this->workspace.'/report.json');
    }

    private function stubTranscriptionByPrompt(ChurchServiceTranscript $primed, ChurchServiceTranscript $unprimed): void
    {
        $transcription = Mockery::mock(ServiceTranscriptionInterface::class);
        $transcription->shouldReceive('transcribeService')
            ->andReturnUsing(static fn (string $path, string $processingId, ?string $prompt = null): ChurchServiceTranscript => $prompt === null ? $primed : $unprimed);

        $this->app->instance(ServiceTranscriptionInterface::class, $transcription);
    }

    private function loopedTranscript(): ChurchServiceTranscript
    {
        $cues = [];

        for ($index = 0; $index < 8; $index++) {
            $cues[] = [
                'start' => 100.0 + ($index * 20),
                'end' => 118.0 + ($index * 20),
                'text' => 'The Holy Spirit is a great song for the Holy Spirit.',
            ];
        }

        $cues[] = ['start' => 300.0, 'end' => 310.0, 'text' => 'Let us turn to our reading.'];

        return ChurchServiceTranscript::fromCues($cues, 1200.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER);
    }

    private function cleanTranscript(): ChurchServiceTranscript
    {
        return ChurchServiceTranscript::fromCues([
            ['start' => 100.0, 'end' => 140.0, 'text' => 'All praise to Him, who’s loved and seen.'],
            ['start' => 300.0, 'end' => 310.0, 'text' => 'Let us turn to our reading.'],
        ], 1200.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER);
    }

    private function bankedAudio(): string
    {
        $path = $this->workspace.'/banked.mp3';
        file_put_contents($path, 'banked audio bytes');

        return $path;
    }

    private function manifest(string $audioPath): string
    {
        $path = $this->workspace.'/manifest.json';
        file_put_contents($path, json_encode([
            'format' => 'crockenhill-transcription-priming-manifest',
            'version' => 1,
            'identities' => [['label' => '2024-01-14-morning', 'audio_path' => $audioPath]],
        ]));

        return $path;
    }
}
