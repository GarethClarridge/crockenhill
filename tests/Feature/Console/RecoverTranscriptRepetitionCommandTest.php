<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Actions\FlagSuspectTranscriptRepetition;
use App\Contracts\ServiceTranscriptionInterface;
use App\Data\ChurchServiceTranscript;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\Media\Audio\MockServiceTranscriptionService;
use App\Services\Media\Audio\ServiceAudioWindowExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RecoverTranscriptRepetitionCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Config::set('media-processing.storage.temp_disk', 'local');
        Config::set('media-processing.storage.transcript_disk', 'local');

        $this->app->bind(ServiceTranscriptionInterface::class, MockServiceTranscriptionService::class);
        $this->swap(ServiceAudioWindowExtractor::class, new class extends ServiceAudioWindowExtractor
        {
            public function extract(string $sourcePath, float $start, float $end, string $processingId): string
            {
                return $sourcePath;
            }

            public function delete(string $path): void {}
        });
    }

    protected function tearDown(): void
    {
        MockServiceTranscriptionService::useTranscript(null);

        parent::tearDown();
    }

    #[Test]
    public function it_refuses_to_run_without_a_selection(): void
    {
        $this->artisan('service:recover-transcript-repetition')
            ->expectsOutputToContain('Name what to recover')
            ->assertFailed();
    }

    #[Test]
    public function it_reports_without_decoding_or_writing_by_default(): void
    {
        [$log, $section] = $this->loopingRun();
        $before = $log->serviceTranscriptPath();

        $report = $this->report(['--run' => [$log->id]]);

        self::assertSame(1, $report['attempted']);
        self::assertSame(0, $report['blocks_recovered']);
        self::assertSame($before, $log->refresh()->serviceTranscriptPath());
        self::assertContains(
            FlagSuspectTranscriptRepetition::FLAG,
            $section->refresh()->metadata?->toArray()['review_flags'] ?? [],
        );
    }

    #[Test]
    public function it_replaces_the_loop_and_withdraws_the_hold(): void
    {
        [$log, $section] = $this->loopingRun();
        $before = $log->serviceTranscriptPath();

        MockServiceTranscriptionService::useTranscript(ChurchServiceTranscript::fromCues([
            ['start' => 30.0, 'end' => 54.0, 'text' => 'Joshua and the campaigns in the south of the land.'],
            ['start' => 54.0, 'end' => 78.0, 'text' => 'And the kings who came out against him there.'],
        ], 108.0, ChurchServiceTranscript::SOURCE_MOCK));

        $report = $this->report(['--run' => [$log->id], '--execute' => true]);

        self::assertSame(1, $report['blocks_recovered']);
        self::assertSame(1, $report['holds_withdrawn']);

        $log->refresh();
        self::assertNotSame($before, $log->serviceTranscriptPath());

        // The pre-recovery transcript survives its own correction.
        Storage::disk('local')->assertExists($before);

        self::assertNotContains(
            FlagSuspectTranscriptRepetition::FLAG,
            $section->refresh()->metadata?->toArray()['review_flags'] ?? [],
        );
    }

    #[Test]
    public function it_makes_the_sermon_derivation_owed_without_re_deriving_it(): void
    {
        // The pass deliberately stops at the transcript, so the stamp is what
        // keeps the run findable once P8-Q15 settles the spans. Without it a
        // recovered run reads as untouched.
        [$log] = $this->loopingRun();
        $log->update(['transcript_file_path' => 'transcripts/sermon_1.md']);
        $log->recordSermonDerivedFrom(MediaProcessingLog::hashServiceTranscriptContent(
            app(\App\Services\Media\Audio\ServiceTranscriptReader::class)->read($log),
        ));

        self::assertFalse($log->refresh()->sermonDerivationIsOwed());

        MockServiceTranscriptionService::useTranscript(ChurchServiceTranscript::fromCues([
            ['start' => 30.0, 'end' => 78.0, 'text' => 'Joshua and the campaigns in the south of the land.'],
        ], 108.0, ChurchServiceTranscript::SOURCE_MOCK));

        $this->report(['--run' => [$log->id], '--execute' => true]);

        $log->refresh();
        self::assertTrue($log->sermonDerivationIsOwed());

        // Stopping at the transcript means the sermon text is left exactly as it
        // was — owed, and visibly so, but not re-derived from spans P8-Q15 has
        // yet to settle.
        self::assertSame('transcripts/sermon_1.md', $log->transcript_file_path);
    }

    #[Test]
    public function it_fails_rather_than_reporting_an_empty_pass_when_a_named_run_is_invisible(): void
    {
        // A run is invisible whenever the app is pointed at another database,
        // which `artisan dusk` does for the length of its suite. Reporting that
        // as "nothing to do" silently skipped 34 runs of a live pass.
        $this->artisan('service:recover-transcript-repetition', ['--run' => [987654], '--execute' => true])
            ->expectsOutputToContain('could not be found')
            ->assertFailed();
    }

    #[Test]
    public function it_skips_a_run_whose_source_is_gone(): void
    {
        [$log] = $this->loopingRun(withSource: false);

        $report = $this->report(['--run' => [$log->id], '--execute' => true]);

        self::assertSame(1, $report['source_unavailable']);
        self::assertSame(0, $report['attempted']);
    }

    #[Test]
    public function it_passes_over_a_run_with_nothing_to_recover(): void
    {
        [$log] = $this->loopingRun(looping: false);

        $report = $this->report(['--run' => [$log->id], '--execute' => true]);

        self::assertSame(1, $report['no_blocks']);
        self::assertSame(0, $report['attempted']);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function report(array $options): array
    {
        Artisan::call('service:recover-transcript-repetition', [...$options, '--json' => true]);

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array{0: MediaProcessingLog, 1: ServiceSection} */
    private function loopingRun(bool $looping = true, bool $withSource = true): array
    {
        $cues = [['start' => 0.0, 'end' => 100.0, 'text' => 'And so we come to the sermon this morning.']];

        if ($looping) {
            for ($index = 0; $index < 12; $index++) {
                $cues[] = [
                    'start' => 100.0 + $index * 4.0,
                    'end' => 104.0 + $index * 4.0,
                    'text' => 'God did what was necessary in order to make sure they won.',
                ];
            }
        }

        $cues[] = ['start' => 200.0, 'end' => 1000.0, 'text' => 'The rest of the sermon, delivered plainly.'];

        $log = MediaProcessingLog::factory()->livestream()->completed()->create([
            'sermon_start_time' => 0.0,
            'sermon_end_time' => 1000.0,
        ]);

        if ($withSource) {
            Storage::disk('local')->put((string) $log->source_file_path, 'fake video bytes');
        } else {
            Storage::disk('local')->delete((string) $log->source_file_path);
        }

        $path = 'temp/service_transcript_'.$log->processing_id.'.json';
        Storage::disk('local')->put($path, json_encode(
            ChurchServiceTranscript::fromCues($cues, 1000.0, ChurchServiceTranscript::SOURCE_MOCK)->toArray(),
            JSON_THROW_ON_ERROR,
        ));
        $log->putServiceTranscriptPath($path);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon,
            'start_time' => 0.0,
            'end_time' => 1000.0,
            'needs_manual_review' => true,
            'metadata' => ['review_flags' => [FlagSuspectTranscriptRepetition::FLAG]],
        ]);

        return [$log->refresh(), $section];
    }
}
