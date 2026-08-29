<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ServiceSectionType;
use App\Jobs\DetectServiceStructure;
use App\Jobs\ExtractSermon;
use App\Jobs\TranscribeFullService;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
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
