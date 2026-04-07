<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\SermonVideoQualityStatus;
use App\Jobs\AssessSermonVideoQuality;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AssessSermonVideoQualityCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function queue_mode_dispatches_matching_unassessed_sermon_videos(): void
    {
        Queue::fake();

        $sermon = Sermon::factory()->create([
            'title' => 'Queued Assessment Sermon',
            'video_file_path' => 'sermons/video/queued.mp4',
            'video_quality_status' => SermonVideoQualityStatus::Unassessed,
        ]);

        Sermon::factory()->create([
            'video_file_path' => 'sermons/video/already-approved.mp4',
            'video_quality_status' => SermonVideoQualityStatus::Approved,
        ]);

        $this->artisan('sermons:assess-video-quality', ['--queue' => true])
            ->expectsOutputToContain("Queued sermon #{$sermon->id}: Queued Assessment Sermon")
            ->expectsOutputToContain('Sermon video quality backfill queued 1 sermon(s).')
            ->assertSuccessful();

        Queue::assertPushedOn('video-processing', AssessSermonVideoQuality::class);
    }

    #[Test]
    public function dry_run_does_not_dispatch_assessment_jobs(): void
    {
        Queue::fake();

        Sermon::factory()->create([
            'title' => 'Dry Run Sermon',
            'video_file_path' => 'sermons/video/dry-run.mp4',
            'video_quality_status' => SermonVideoQualityStatus::Unassessed,
        ]);

        $this->artisan('sermons:assess-video-quality', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN enabled.')
            ->expectsOutputToContain('Sermon video quality backfill matched 1 sermon(s).')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }
}
