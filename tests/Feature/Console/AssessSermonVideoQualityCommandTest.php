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

    #[Test]
    public function the_reason_filter_bounds_recovery_to_one_class_of_failure(): void
    {
        Queue::fake();

        $recoverable = Sermon::factory()->create([
            'title' => 'Missing File Sermon',
            'video_file_path' => 'sermons/video/missing.mp4',
            'video_quality_status' => SermonVideoQualityStatus::Unassessed,
            'video_quality_reason' => 'missing_video_file',
        ]);

        $unrelated = Sermon::factory()->create([
            'title' => 'Analysis Failed Sermon',
            'video_file_path' => 'sermons/video/failed.mp4',
            'video_quality_status' => SermonVideoQualityStatus::Unassessed,
            'video_quality_reason' => 'analysis_failed',
        ]);

        $this->artisan('sermons:assess-video-quality', [
            '--queue' => true,
            '--reason' => 'missing_video_file',
        ])
            ->expectsOutputToContain("Queued sermon #{$recoverable->id}: Missing File Sermon")
            ->doesntExpectOutputToContain("Queued sermon #{$unrelated->id}")
            ->expectsOutputToContain('Sermon video quality backfill queued 1 sermon(s).')
            ->assertSuccessful();

        Queue::assertPushed(AssessSermonVideoQuality::class, 1);
    }

    #[Test]
    public function sermons_on_an_unreachable_disk_are_reported_as_deferred_not_assessed(): void
    {
        Queue::fake();

        config(['filesystems.disks.detached_volume' => [
            'driver' => 'local',
            'root' => '/nonexistent/detached-volume',
        ]]);

        $sermon = Sermon::factory()->create([
            'title' => 'Detached Volume Sermon',
            'video_file_path' => 'sermons/video/detached.mp4',
            'asset_disk' => 'detached_volume',
            'video_quality_status' => SermonVideoQualityStatus::Unassessed,
            'video_quality_reason' => 'missing_video_file',
        ]);

        $this->artisan('sermons:assess-video-quality', ['--queue' => true])
            ->expectsOutputToContain("Deferred sermon #{$sermon->id}:")
            ->expectsOutputToContain('Sermon video quality backfill queued 0 sermon(s).')
            ->expectsOutputToContain('1 sermon(s) were deferred because their owning disk is unreachable')
            ->assertSuccessful();

        Queue::assertNothingPushed();

        $sermon->refresh();

        $this->assertSame(SermonVideoQualityStatus::Unassessed, $sermon->video_quality_status);
        $this->assertSame('missing_video_file', $sermon->video_quality_reason);
    }
}
