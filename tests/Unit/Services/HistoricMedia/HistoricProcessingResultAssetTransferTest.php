<?php

declare(strict_types=1);

namespace Tests\Unit\Services\HistoricMedia;

use App\Services\HistoricMedia\HistoricProcessingResultAssetTransfer;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class HistoricProcessingResultAssetTransferTest extends TestCase
{
    private const LARGE_ASSET_BYTES = 8 * 1024 * 1024;

    /**
     * A whole-file read of the fixture would grow real allocation by at least the
     * file size, so a ceiling well under it is what proves the copy streams.
     */
    private const MEMORY_CEILING_BYTES = 3 * 1024 * 1024;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('historic_staging');
        Storage::fake('local');
        config()->set('media-processing.storage.historic_staging_disk', 'historic_staging');
        // Transfers target the quarantine disk, not the public sermon disk.
        config()->set('media-processing.storage.historic_quarantine_disk', 'local');
        config()->set('media-processing.storage.sermon_disk', 'public');
    }

    #[Test]
    public function large_assets_are_hashed_verified_and_copied_only_as_streams(): void
    {
        $asset = $this->stageLargeAsset('historic/run/full-service.mp4', ['run_video_file_path']);
        $transfer = app(HistoricProcessingResultAssetTransfer::class);
        $destinations = ['run_video_file_path' => 'service-transcripts/run/full-service.mp4'];

        gc_collect_cycles();
        $baseline = memory_get_usage(true);
        memory_reset_peak_usage();

        $created = $transfer->copyToDestinations([$asset], $destinations);

        $growth = memory_get_peak_usage(true) - $baseline;

        $this->assertSame(['service-transcripts/run/full-service.mp4'], $created);
        Storage::disk('local')->assertExists('service-transcripts/run/full-service.mp4');
        $this->assertSame(
            self::LARGE_ASSET_BYTES,
            Storage::disk('local')->size('service-transcripts/run/full-service.mp4'),
        );
        $this->assertLessThan(
            self::MEMORY_CEILING_BYTES,
            $growth,
            'Copying a large historic asset allocated enough memory to suggest a whole-file read.',
        );
    }

    #[Test]
    public function verify_staged_streams_large_assets_within_the_memory_ceiling(): void
    {
        $asset = $this->stageLargeAsset('historic/run/full-service.mp4', ['run_video_file_path']);
        $transfer = app(HistoricProcessingResultAssetTransfer::class);

        gc_collect_cycles();
        $baseline = memory_get_usage(true);
        memory_reset_peak_usage();

        $transfer->verifyStaged([$asset]);

        $this->assertLessThan(
            self::MEMORY_CEILING_BYTES,
            memory_get_peak_usage(true) - $baseline,
            'Verifying a large staged asset allocated enough memory to suggest a whole-file read.',
        );
    }

    #[Test]
    public function it_fans_shared_content_out_to_every_allocated_destination(): void
    {
        Storage::disk('historic_staging')->put('historic/run/shared.mp3', 'shared audio');
        $asset = [
            'path' => 'historic/run/shared.mp3',
            'size' => 12,
            'sha256' => hash('sha256', 'shared audio'),
            'kind' => 'audio',
            'roles' => ['run_audio_file_path', 'publication:main:audio_file_path'],
        ];

        $created = app(HistoricProcessingResultAssetTransfer::class)->copyToDestinations([$asset], [
            'run_audio_file_path' => 'service-transcripts/run/full-service.mp3',
            'publication:main:audio_file_path' => 'sermons/7/audio.mp3',
        ]);

        $this->assertSame([
            'service-transcripts/run/full-service.mp3',
            'sermons/7/audio.mp3',
        ], $created);
        Storage::disk('local')->assertExists('service-transcripts/run/full-service.mp3');
        Storage::disk('local')->assertExists('sermons/7/audio.mp3');
    }

    #[Test]
    public function it_rejects_corruption_at_the_staging_boundary(): void
    {
        Storage::disk('historic_staging')->put('historic/run/audio.mp3', 'tampered');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('differs from its manifest');

        app(HistoricProcessingResultAssetTransfer::class)->copyToDestinations([[
            'path' => 'historic/run/audio.mp3',
            'size' => 5,
            'sha256' => hash('sha256', 'audio'),
            'kind' => 'audio',
            'roles' => ['run_audio_file_path'],
        ]], ['run_audio_file_path' => 'sermons/7/audio.mp3']);
    }

    #[Test]
    public function it_rejects_a_pre_existing_destination_whose_content_differs(): void
    {
        Storage::disk('historic_staging')->put('historic/run/audio.mp3', 'audio');
        Storage::disk('local')->put('sermons/7/audio.mp3', 'AUDIO');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('differs from its manifest');

        app(HistoricProcessingResultAssetTransfer::class)->copyToDestinations([[
            'path' => 'historic/run/audio.mp3',
            'size' => 5,
            'sha256' => hash('sha256', 'audio'),
            'kind' => 'audio',
            'roles' => ['run_audio_file_path'],
        ]], ['run_audio_file_path' => 'sermons/7/audio.mp3']);
    }

    #[Test]
    public function it_leaves_a_matching_pre_existing_destination_uncompensated(): void
    {
        Storage::disk('historic_staging')->put('historic/run/audio.mp3', 'audio');
        Storage::disk('historic_staging')->put('historic/run/video.mp4', 'video');
        Storage::disk('local')->put('sermons/7/audio.mp3', 'audio');

        try {
            app(HistoricProcessingResultAssetTransfer::class)->copyToDestinations([
                [
                    'path' => 'historic/run/audio.mp3',
                    'size' => 5,
                    'sha256' => hash('sha256', 'audio'),
                    'kind' => 'audio',
                    'roles' => ['run_audio_file_path'],
                ],
                [
                    'path' => 'historic/run/video.mp4',
                    'size' => 5,
                    'sha256' => hash('sha256', 'video'),
                    'kind' => 'video',
                    'roles' => ['run_video_file_path'],
                ],
            ], ['run_audio_file_path' => 'sermons/7/audio.mp3']);

            $this->fail('An unallocated asset role should abort the copy.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('No production path was allocated', $exception->getMessage());
        }

        Storage::disk('local')->assertExists('sermons/7/audio.mp3');
        $this->assertSame('audio', Storage::disk('local')->get('sermons/7/audio.mp3'));
    }

    #[Test]
    public function it_compensates_every_object_it_created_before_failing(): void
    {
        Storage::disk('historic_staging')->put('historic/run/audio.mp3', 'audio');
        Storage::disk('historic_staging')->put('historic/run/video.mp4', 'video');

        try {
            app(HistoricProcessingResultAssetTransfer::class)->copyToDestinations([
                [
                    'path' => 'historic/run/audio.mp3',
                    'size' => 5,
                    'sha256' => hash('sha256', 'audio'),
                    'kind' => 'audio',
                    'roles' => ['run_audio_file_path'],
                ],
                [
                    'path' => 'historic/run/video.mp4',
                    'size' => 5,
                    'sha256' => hash('sha256', 'not the staged video'),
                    'kind' => 'video',
                    'roles' => ['run_video_file_path'],
                ],
            ], [
                'run_audio_file_path' => 'sermons/7/audio.mp3',
                'run_video_file_path' => 'sermons/7/video.mp4',
            ]);

            $this->fail('A manifest mismatch should abort the copy.');
        } catch (RuntimeException) {
            // Asserted below: the first copy must not survive the failure.
        }

        Storage::disk('local')->assertMissing('sermons/7/audio.mp3');
        Storage::disk('local')->assertMissing('sermons/7/video.mp4');
    }

    #[Test]
    public function direct_pipeline_copy_uses_exact_sizes_for_new_destinations(): void
    {
        Storage::disk('historic_staging')->put('sermons/songs/7/1.mp4', 'song video');

        $created = app(HistoricProcessingResultAssetTransfer::class)->copyPipelineAssetsToDestinations([[
            'path' => 'sermons/songs/7/1.mp4',
            'size' => strlen('song video'),
            'kind' => 'song_video',
            'roles' => ['song_video'],
        ]], ['song_video' => 'sermons/songs/7/1.mp4']);

        $this->assertSame(['sermons/songs/7/1.mp4'], $created);
        Storage::disk('local')->assertExists('sermons/songs/7/1.mp4');
    }

    #[Test]
    public function direct_pipeline_copy_hashes_only_an_existing_destination_to_classify_conflict(): void
    {
        Storage::disk('historic_staging')->put('sermons/songs/7/1.mp4', 'song-a');
        Storage::disk('local')->put('sermons/songs/7/1.mp4', 'song-b');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('differs from existing destination');

        app(HistoricProcessingResultAssetTransfer::class)->copyPipelineAssetsToDestinations([[
            'path' => 'sermons/songs/7/1.mp4',
            'size' => 6,
            'kind' => 'song_video',
            'roles' => ['song_video'],
        ]], ['song_video' => 'sermons/songs/7/1.mp4']);
    }

    #[Test]
    public function direct_pipeline_copy_removes_only_new_destinations_after_a_partial_failure(): void
    {
        Storage::disk('historic_staging')->put('sermons/songs/7/1.mp4', 'song-a');
        Storage::disk('historic_staging')->put('sermons/songs/7/2.mp4', 'too short');

        $this->expectException(RuntimeException::class);

        try {
            app(HistoricProcessingResultAssetTransfer::class)->copyPipelineAssetsToDestinations([
                [
                    'path' => 'sermons/songs/7/1.mp4',
                    'size' => 6,
                    'kind' => 'song_video',
                    'roles' => ['first'],
                ],
                [
                    'path' => 'sermons/songs/7/2.mp4',
                    'size' => 999,
                    'kind' => 'song_video',
                    'roles' => ['second'],
                ],
            ], [
                'first' => 'sermons/songs/7/1.mp4',
                'second' => 'sermons/songs/7/2.mp4',
            ]);
        } finally {
            Storage::disk('local')->assertMissing('sermons/songs/7/1.mp4');
            Storage::disk('local')->assertMissing('sermons/songs/7/2.mp4');
            Storage::disk('historic_staging')->assertExists('sermons/songs/7/1.mp4');
        }
    }

    #[Test]
    public function it_rejects_a_staged_path_that_escapes_the_staging_root(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsafe bundle asset path');

        app(HistoricProcessingResultAssetTransfer::class)->verifyStaged([[
            'path' => '../outside/audio.mp3',
            'size' => 5,
            'sha256' => hash('sha256', 'audio'),
            'kind' => 'audio',
            'roles' => ['run_audio_file_path'],
        ]]);
    }

    #[Test]
    public function it_refuses_to_transfer_when_staging_and_the_import_target_share_a_disk(): void
    {
        config()->set('media-processing.storage.historic_quarantine_disk', 'historic_staging');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Historic staging and production media disks must be distinct for import.');

        app(HistoricProcessingResultAssetTransfer::class)->verifyStaged([]);
    }

    #[Test]
    public function it_refuses_to_transfer_when_the_import_target_disk_is_unconfigured(): void
    {
        config()->set('media-processing.storage.historic_quarantine_disk', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Historic quarantine media disk is not configured.');

        app(HistoricProcessingResultAssetTransfer::class)->verifyStaged([]);
    }

    /**
     * @param  list<string>  $roles
     * @return array{path: string, size: int, sha256: string, kind: string, roles: list<string>}
     */
    private function stageLargeAsset(string $path, array $roles): array
    {
        $chunk = str_repeat('historic-media-', 1024);
        Storage::disk('historic_staging')->put($path, '');
        $handle = fopen(Storage::disk('historic_staging')->path($path), 'wb');

        if (! is_resource($handle)) {
            $this->fail("Could not stage a large fixture at {$path}.");
        }

        $context = hash_init('sha256');
        $written = 0;

        while ($written < self::LARGE_ASSET_BYTES) {
            $slice = substr($chunk, 0, min(strlen($chunk), self::LARGE_ASSET_BYTES - $written));
            fwrite($handle, $slice);
            hash_update($context, $slice);
            $written += strlen($slice);
        }

        fclose($handle);

        return [
            'path' => $path,
            'size' => self::LARGE_ASSET_BYTES,
            'sha256' => hash_final($context),
            'kind' => 'video',
            'roles' => $roles,
        ];
    }
}
