<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Actions\HoldSectionForContentReview;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Jobs\CleanupTemporaryFiles;
use App\Jobs\DetectServiceStructure;
use App\Jobs\ExtractSermon;
use App\Jobs\TranscribeFullService;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\Media\Video\VideoStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReExtractSermonCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['media-processing.storage.temp_disk' => 'local']);
    }

    #[Test]
    public function it_reports_the_span_change_without_dispatching_on_a_dry_run(): void
    {
        Bus::fake();

        $log = $this->completedRunWithTrailingPrayer();

        $this->artisan('sermons:re-extract', ['processing_id' => $log->processing_id, '--dry-run' => true])
            ->expectsOutputToContain('2260.0')
            ->assertExitCode(0);

        Bus::assertNothingDispatched();
    }

    /**
     * A finished run has usually had its source deleted by CleanupTemporaryFiles,
     * so the command must say so plainly rather than dispatch a chain that will
     * fail deep inside ffmpeg.
     */
    #[Test]
    public function it_refuses_when_the_source_media_has_been_cleaned_up(): void
    {
        Bus::fake();

        $log = $this->completedRunWithTrailingPrayer(withSource: false);

        $this->artisan('sermons:re-extract', ['processing_id' => $log->processing_id])
            ->expectsOutputToContain('source media for this run is gone')
            ->assertExitCode(1);

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function it_refuses_a_run_that_has_no_service_sections(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        $this->artisan('sermons:re-extract', ['processing_id' => $log->processing_id])
            ->expectsOutputToContain('no service sections')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_reports_an_unknown_processing_id(): void
    {
        $this->artisan('sermons:re-extract', ['processing_id' => 'does-not-exist'])
            ->expectsOutputToContain('No processing run found')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_dispatches_the_chain_from_the_extraction_phase_when_confirmed(): void
    {
        Bus::fake();

        $log = $this->completedRunWithTrailingPrayer();

        $this->artisan('sermons:re-extract', ['processing_id' => $log->processing_id])
            ->expectsConfirmation('Re-cut and republish this sermon?', 'yes')
            ->expectsOutputToContain('Re-extraction dispatched')
            ->assertExitCode(0);

        // The chain head is the extraction job: detection, transcription and the
        // RMS log are deliberately not re-run.
        Bus::assertDispatched(ExtractSermon::class, function (ExtractSermon $job): bool {
            return $job->chained !== [];
        });
        Bus::assertNotDispatched(DetectServiceStructure::class);
        Bus::assertNotDispatched(TranscribeFullService::class);
    }

    #[Test]
    public function it_reextracts_from_a_source_retained_for_a_review_obligation_without_restaging(): void
    {
        Bus::fake();

        $log = $this->completedRunWithTrailingPrayer();
        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::ShortTalk,
            'section_order' => 1,
            'start_time' => 100.0,
            'end_time' => 140.0,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval,
        ]);

        (new CleanupTemporaryFiles($log))->handle(app(VideoStorageService::class));

        Storage::disk('local')->assertExists((string) $log->source_file_path);

        $this->artisan('sermons:re-extract', ['processing_id' => $log->processing_id, '--yes' => true])
            ->expectsOutputToContain('Re-extraction dispatched')
            ->assertExitCode(0);

        Bus::assertDispatched(ExtractSermon::class);
    }

    /**
     * 1209, 2026-09-17: the dry run printed the recorded bounds and success, then the
     * job refused, because a held sermon sends the plan to the RMS baseline. A plan
     * that did not come from the structure is not the re-cut this command promises.
     */
    #[Test]
    public function it_refuses_a_plan_that_falls_back_to_the_recorded_bounds(): void
    {
        Bus::fake();

        [$log, $sermon] = $this->completedRunWithHeldSermon();

        $this->artisan('sermons:re-extract', ['processing_id' => $log->processing_id, '--dry-run' => true])
            ->expectsOutputToContain('sermon_section_content_held')
            ->expectsOutputToContain("--held-section={$sermon->id}")
            ->assertExitCode(1);

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function it_plans_from_a_named_held_sermon_on_a_dry_run_without_recording_anything(): void
    {
        Bus::fake();

        [$log, $sermon] = $this->completedRunWithHeldSermon();

        $this->artisan('sermons:re-extract', [
            'processing_id' => $log->processing_id,
            '--held-section' => $sermon->id,
            '--dry-run' => true,
        ])
            // The sermon runs on through the closing prayer, as for any sermon section.
            ->expectsOutputToContain('2123.0s - 3800.0s')
            ->expectsOutputToContain("Held:     section {$sermon->id} stays held")
            ->assertExitCode(0);

        Bus::assertNothingDispatched();
        $this->assertNull($log->fresh()->authorisedHeldSermonSpan());
    }

    /**
     * The re-cut is authorised; the release is not. The section stays held.
     */
    #[Test]
    public function it_records_the_authority_and_keeps_the_hold_when_re_cutting_a_named_held_sermon(): void
    {
        Bus::fake();

        [$log, $sermon] = $this->completedRunWithHeldSermon();

        $this->artisan('sermons:re-extract', [
            'processing_id' => $log->processing_id,
            '--held-section' => $sermon->id,
            '--yes' => true,
        ])
            ->expectsOutputToContain('Re-extraction dispatched')
            ->assertExitCode(0);

        Bus::assertDispatched(ExtractSermon::class);

        $authority = $log->fresh()->authorisedHeldSermonSpan();
        $this->assertSame($sermon->id, $authority['section_id'] ?? null);

        $sermon->refresh();
        $this->assertTrue($sermon->needs_manual_review);
        $this->assertTrue(HoldSectionForContentReview::isHeld($sermon->metadata->reviewFlags ?? []));
    }

    #[Test]
    public function it_refuses_a_named_section_that_is_not_a_content_held_sermon_of_the_run(): void
    {
        Bus::fake();

        [$log] = $this->completedRunWithHeldSermon();
        $prayer = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->where('section_type', ServiceSectionType::Prayer->value)
            ->sole();
        $otherRunsSermon = ServiceSection::factory()->create([
            'media_processing_log_id' => MediaProcessingLog::factory()->livestream()->completed()->create()->id,
            'section_type' => ServiceSectionType::Sermon->value,
        ]);

        foreach ([$prayer->id, $otherRunsSermon->id, 999999] as $sectionId) {
            $this->artisan('sermons:re-extract', [
                'processing_id' => $log->processing_id,
                '--held-section' => $sectionId,
                '--yes' => true,
            ])
                ->expectsOutputToContain('--held-section must name a content-held sermon section of this run')
                ->assertExitCode(1);
        }

        Bus::assertNothingDispatched();
        $this->assertNull($log->fresh()->authorisedHeldSermonSpan());
    }

    /**
     * A span a reviewer confirmed is a human decision, not a fallback; only the
     * recorded-bounds baseline is refused.
     */
    #[Test]
    public function it_re_cuts_identified_sections_instead_of_a_legacy_confirmed_rms_segment(): void
    {
        Bus::fake();

        $log = $this->completedRunWithTrailingPrayer();
        $segment = LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $log->id,
            'start_time' => 640.0,
            'end_time' => 2050.0,
        ]);
        $log->update(['processing_metadata' => ['manual_review' => ['confirmed_segment_id' => $segment->id]]]);

        $this->artisan('sermons:re-extract', ['processing_id' => $log->processing_id, '--dry-run' => true])
            ->expectsOutputToContain('630.0s - 2260.0s')
            ->assertExitCode(0);

        Bus::assertNothingDispatched();
    }

    /**
     * @return array{MediaProcessingLog, ServiceSection}
     */
    private function completedRunWithHeldSermon(): array
    {
        $sourcePath = 'livestream/temp/held.webm';
        Storage::disk('local')->put($sourcePath, 'video-bytes');

        $log = MediaProcessingLog::factory()->livestream()->completed()->create([
            'source_file_path' => $sourcePath,
            'sermon_start_time' => 1758.9,
            'sermon_end_time' => 3740.0,
        ]);

        $sermon = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'section_order' => 1,
            'start_time' => 2123.0,
            'end_time' => 3740.0,
            'duration' => 1617.0,
            'needs_manual_review' => true,
            'metadata' => [
                'confidence_level' => 'high',
                'review_flags' => [HoldSectionForContentReview::FLAG],
                HoldSectionForContentReview::METADATA_KEY => [[
                    'reason' => 'Sermon MP3 loses the closing words',
                    'evidence' => 'duration census',
                    'held_at' => '2026-09-13T20:23:31+00:00',
                ]],
            ],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Prayer->value,
            'section_order' => 2,
            'start_time' => 3745.0,
            'end_time' => 3800.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        return [$log, $sermon];
    }

    private function completedRunWithTrailingPrayer(bool $withSource = true): MediaProcessingLog
    {
        $sourcePath = 'livestream/temp/source.mkv';

        if ($withSource) {
            Storage::disk('local')->put($sourcePath, 'video-bytes');
        }

        $log = MediaProcessingLog::factory()->livestream()->completed()->create([
            'source_file_path' => $sourcePath,
            'sermon_start_time' => 630.0,
            'sermon_end_time' => 2100.0,
        ]);

        foreach ([
            [ServiceSectionType::Sermon, 2, 630.0, 2100.0],
            [ServiceSectionType::Prayer, 3, 2110.0, 2260.0],
        ] as [$type, $order, $start, $end]) {
            ServiceSection::factory()->create([
                'media_processing_log_id' => $log->id,
                'section_type' => $type->value,
                'section_order' => $order,
                'start_time' => $start,
                'end_time' => $end,
                'needs_manual_review' => false,
                'metadata' => ['confidence_level' => 'high'],
            ]);
        }

        return $log;
    }
}
