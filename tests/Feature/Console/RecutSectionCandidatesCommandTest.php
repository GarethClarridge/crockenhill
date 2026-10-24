<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ProcessingStatus;
use App\Enums\ServiceSectionType;
use App\Jobs\ExtractSermon;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RecutSectionCandidatesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['media-processing.storage.temp_disk' => 'local']);
        Bus::fake();
    }

    /** 10-24: the sermon re-cut was 82 of the 95 minutes nineteen songs' recorded starts took. */
    #[Test]
    public function it_re_cuts_the_clips_without_re_cutting_the_sermon(): void
    {
        $run = $this->finishedRun();

        $this->artisan('sections:recut', ['processing_ids' => [$run->processing_id]])
            ->expectsOutputToContain('re-cut dispatched')
            ->assertExitCode(0);

        Bus::assertDispatched(PrepareSectionPublicationCandidates::class, static fn (PrepareSectionPublicationCandidates $job): bool => $job->standalone
            && $job->processingLog->is($run));
        Bus::assertNotDispatched(ExtractSermon::class);
    }

    #[Test]
    public function a_dry_run_dispatches_nothing(): void
    {
        $run = $this->finishedRun();

        $this->artisan('sections:recut', ['processing_ids' => [$run->processing_id], '--dry-run' => true])
            ->expectsOutputToContain('would re-cut')
            ->assertExitCode(0);

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function it_refuses_a_run_whose_source_is_gone(): void
    {
        $run = $this->finishedRun(withSource: false);

        $this->artisan('sections:recut', ['processing_ids' => [$run->processing_id]])
            ->expectsOutputToContain('source media is gone')
            ->assertExitCode(1);

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function it_refuses_a_run_still_processing(): void
    {
        $run = $this->finishedRun();
        $run->forceFill(['status' => ProcessingStatus::Processing])->save();

        $this->artisan('sections:recut', ['processing_ids' => [$run->processing_id]])
            ->expectsOutputToContain('still processing')
            ->assertExitCode(1);

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function it_refuses_a_run_without_sections(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->completed()->create();

        $this->artisan('sections:recut', ['processing_ids' => [$run->processing_id]])
            ->expectsOutputToContain('no service sections')
            ->assertExitCode(1);
    }

    /** One refusal does not stop the others in a batch, but the command reports the failure. */
    #[Test]
    public function it_dispatches_the_rest_of_a_batch_past_an_unknown_run(): void
    {
        $run = $this->finishedRun();

        $this->artisan('sections:recut', ['processing_ids' => ['no-such-run', $run->processing_id]])
            ->expectsOutputToContain('no processing run has this ID')
            ->expectsOutputToContain('re-cut dispatched')
            ->assertExitCode(1);

        Bus::assertDispatchedTimes(PrepareSectionPublicationCandidates::class, 1);
    }

    private function finishedRun(bool $withSource = true): MediaProcessingLog
    {
        $sourcePath = 'livestream/temp/source.mkv';

        if ($withSource) {
            Storage::disk('local')->put($sourcePath, 'video-bytes');
        }

        $run = MediaProcessingLog::factory()->livestream()->completed()->create(['source_file_path' => $sourcePath]);
        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song->value,
            'start_time' => 258.0,
            'end_time' => 480.0,
        ]);

        return $run;
    }
}
