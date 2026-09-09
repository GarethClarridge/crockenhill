<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Actions\FlagSuspectTranscriptRepetition;
use App\Data\ChurchServiceTranscript;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScreenTranscriptRepetitionCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Config::set('media-processing.storage.temp_disk', 'local');
        Config::set('media-processing.storage.transcript_disk', 'local');
    }

    #[Test]
    public function it_refuses_to_run_without_a_selection(): void
    {
        // P8-Q3's lesson: a bulk pass that was meant for one operation is not
        // recoverable by re-running it.
        $this->artisan('service:screen-transcript-repetition')
            ->expectsOutputToContain('Name what to screen')
            ->assertFailed();
    }

    #[Test]
    public function it_reports_without_writing_anything(): void
    {
        [$log, $section] = $this->loopingRun();

        $this->artisan('service:screen-transcript-repetition', ['--run' => [$log->id]])
            ->expectsOutputToContain('Nothing was written')
            ->assertSuccessful();

        $section->refresh();
        $this->assertSame([], $section->metadata?->toArray()['review_flags'] ?? []);
        $this->assertFalse($section->needs_manual_review);
        $this->assertNull($log->refresh()->recordedTranscriptSuspectBlocks());
    }

    #[Test]
    public function it_records_the_screen_and_holds_the_sermon_when_applied(): void
    {
        [$log, $section] = $this->loopingRun();

        $this->artisan('service:screen-transcript-repetition', ['--run' => [$log->id], '--apply' => true])
            ->assertSuccessful();

        $section->refresh();
        $this->assertContains(FlagSuspectTranscriptRepetition::FLAG, $section->metadata?->toArray()['review_flags'] ?? []);
        $this->assertTrue($section->needs_manual_review);

        $blocks = $log->refresh()->recordedTranscriptSuspectBlocks();
        $this->assertNotNull($blocks);
        $this->assertCount(1, $blocks);
        $this->assertSame(12, $blocks[0]['repeats']);
    }

    #[Test]
    public function it_is_idempotent(): void
    {
        [$log] = $this->loopingRun();

        $this->artisan('service:screen-transcript-repetition', ['--run' => [$log->id], '--apply' => true])->assertSuccessful();

        $report = $this->report(['--run' => [$log->id], '--apply' => true]);

        $this->assertSame(0, $report['holds_raised']);
        $this->assertSame(0, $report['holds_withdrawn']);
    }

    #[Test]
    public function it_withdraws_a_hold_once_the_transcript_no_longer_loops(): void
    {
        [$log, $section] = $this->loopingRun(looping: false);
        $section->forceFill([
            'needs_manual_review' => true,
            'metadata' => ['review_flags' => [FlagSuspectTranscriptRepetition::FLAG]],
        ])->save();

        $this->assertSame(1, $this->report(['--run' => [$log->id], '--apply' => true])['holds_withdrawn']);

        $section->refresh();
        $this->assertNotContains(FlagSuspectTranscriptRepetition::FLAG, $section->metadata?->toArray()['review_flags'] ?? []);
        $this->assertFalse($section->needs_manual_review);
    }

    #[Test]
    public function it_counts_a_run_whose_transcript_cannot_be_read(): void
    {
        // The evidence being unreachable is a distinct outcome from the evidence
        // being clean, and a run whose staging volume is detached reads exactly
        // like a run with nothing to find.
        $log = MediaProcessingLog::factory()->livestream()->completed()->create();
        $log->putServiceTranscriptPath('service-transcripts/unknown-date/missing.json');

        $report = $this->report(['--run' => [$log->id]]);

        $this->assertSame(1, $report['unreadable']);
        $this->assertSame(0, $report['screened']);
    }

    /**
     * The command's JSON report.
     *
     * Decoded rather than asserted against the console, because the whole report
     * is emitted as a single `line()` call and chained `expectsOutputToContain`
     * assertions consume that buffer one at a time.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function report(array $options): array
    {
        $this->assertSame(0, Artisan::call('service:screen-transcript-repetition', [...$options, '--json' => true]));

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array{0: MediaProcessingLog, 1: ServiceSection} */
    private function loopingRun(bool $looping = true): array
    {
        $cues = [['start' => 0.0, 'end' => 100.0, 'text' => 'And so we come to the sermon.']];

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
            'needs_manual_review' => false,
            'metadata' => ['review_flags' => []],
        ]);

        return [$log->refresh(), $section];
    }
}
