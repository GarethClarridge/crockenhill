<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\SongVideo;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PrivatiseSongVideosTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function dry_run_lists_only_the_selected_disk_and_does_not_change_visibility(): void
    {
        $disk = Storage::fake('song_cloud');
        config(['filesystems.disks.song_cloud.driver' => 's3']);
        $path = 'sermons/songs/1/video.mp4';
        $disk->put($path, 'video', 'public');
        SongVideo::factory()->create(['asset_disk' => 'song_cloud', 'video_file_path' => $path]);
        SongVideo::factory()->create(['asset_disk' => 'elsewhere']);

        $this->artisan('songs:privatise-videos', ['--disk' => 'song_cloud'])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain($path)
            ->assertSuccessful();
        $this->assertSame('public', $disk->getVisibility($path));
    }

    #[Test]
    public function explicit_apply_changes_only_song_objects_and_is_repeatable(): void
    {
        $disk = Storage::fake('song_cloud');
        config(['filesystems.disks.song_cloud.driver' => 's3']);
        config(['media-processing.storage.sermon_disk' => 'song_cloud']);
        $path = 'sermons/songs/1/video.mp4';
        $disk->put($path, 'video', 'public');
        $disk->put('sermons/sermon.mp4', 'sermon', 'public');
        SongVideo::factory()->create(['asset_disk' => null, 'video_file_path' => $path]);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->artisan('songs:privatise-videos', ['--disk' => 'song_cloud', '--apply' => true])->assertSuccessful();
        }
        $this->assertSame('private', $disk->getVisibility($path));
        $this->assertSame('public', $disk->getVisibility('sermons/sermon.mp4'));
    }

    #[Test]
    public function a_failed_acl_write_stops_with_a_failure_status(): void
    {
        config(['filesystems.disks.song_cloud.driver' => 's3']);
        $path = 'sermons/songs/1/video.mp4';
        SongVideo::factory()->create(['asset_disk' => 'song_cloud', 'video_file_path' => $path]);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->once()->with($path)->andReturnTrue();
        $disk->shouldReceive('setVisibility')->once()->with($path, 'private')->andReturnFalse();
        Storage::shouldReceive('disk')->once()->with('song_cloud')->andReturn($disk);

        $this->artisan('songs:privatise-videos', ['--disk' => 'song_cloud', '--apply' => true])
            ->expectsOutputToContain('Could not verify private visibility')
            ->assertFailed();
    }

    #[Test]
    public function unsafe_keys_and_local_disks_are_refused_before_any_changes(): void
    {
        $disk = Storage::fake('song_cloud');
        config(['filesystems.disks.song_cloud.driver' => 's3']);
        $path = 'sermons/songs/1/video.mp4';
        $disk->put($path, 'video', 'public');
        SongVideo::factory()->create(['asset_disk' => 'song_cloud', 'video_file_path' => $path]);
        SongVideo::factory()->create(['asset_disk' => 'song_cloud', 'video_file_path' => '../sermon.mp4']);
        $this->artisan('songs:privatise-videos', ['--disk' => 'song_cloud', '--apply' => true])->assertFailed();
        $this->assertSame('public', $disk->getVisibility($path));
        $this->artisan('songs:privatise-videos', ['--disk' => 'public', '--apply' => true])->assertFailed();
    }
}
