<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Data\ThumbnailMetadata;
use App\Enums\SermonContentType;
use App\Jobs\MoveSermonToPrivateStorage;
use App\Models\Sermon;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MoveSermonToPrivateStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        Storage::fake('transcripts');

        config([
            'thumbnail-generation.storage.disk' => 'public',
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.storage.transcript_disk' => 'transcripts',
        ]);
    }

    #[Test]
    public function it_moves_audio_file_to_private_storage(): void
    {
        Storage::disk('public')->put('sermons/audio.mp3', 'audio-content');

        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'sermons/audio.mp3',
            'content_type' => SermonContentType::ChildrensTalk,
        ]);

        (new MoveSermonToPrivateStorage($sermon->id))->handle();

        $sermon->refresh();

        $this->assertSame('private/sermons/audio.mp3', $sermon->audio_file_path);
        Storage::disk('local')->assertExists('private/sermons/audio.mp3');
        Storage::disk('public')->assertMissing('sermons/audio.mp3');
    }

    #[Test]
    public function it_moves_every_protected_asset_and_removes_each_public_source(): void
    {
        $sermonPaths = [
            'sermons/audio.mp3' => 'audio',
            'sermons/video.mp4' => 'video',
        ];
        $thumbnailPaths = [
            'thumbs/primary.webp' => 'primary',
            'thumbs/plain.webp' => 'plain',
            'thumbs/card.webp' => 'card',
            'thumbs/candidate-plain.webp' => 'candidate plain',
            'thumbs/candidate-card.webp' => 'candidate card',
            'thumbs/candidate-overlay.webp' => 'candidate overlay',
        ];

        foreach ($sermonPaths + $thumbnailPaths as $path => $contents) {
            Storage::disk('public')->put($path, $contents);
        }
        Storage::disk('transcripts')->put('transcripts/talk.md', 'transcript');

        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'audio_file_path' => 'sermons/audio.mp3',
            'video_file_path' => 'sermons/video.mp4',
            'transcript_file_path' => 'transcripts/talk.md',
            'thumbnail_file_path' => 'thumbs/primary.webp',
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'thumbs/plain.webp',
                'card_thumbnail_path' => 'thumbs/card.webp',
                'overlay_thumbnail_path' => 'thumbs/candidate-overlay.webp',
                'thumbnail_candidates' => [[
                    'id' => 'candidate-1',
                    'timestamp' => 10.0,
                    'score' => 0.9,
                    'plain_path' => 'thumbs/candidate-plain.webp',
                    'card_path' => 'thumbs/candidate-card.webp',
                    'overlay_path' => 'thumbs/candidate-overlay.webp',
                ]],
            ],
        ]);

        (new MoveSermonToPrivateStorage($sermon->id))->handle();

        $sermon->refresh();

        $this->assertSame('private/sermons/audio.mp3', $sermon->audio_file_path);
        $this->assertSame('private/sermons/video.mp4', $sermon->video_file_path);
        $this->assertSame('private/transcripts/talk.md', $sermon->transcript_file_path);
        $this->assertSame('private/thumbs/primary.webp', $sermon->thumbnail_file_path);
        $this->assertSame('private/thumbs/plain.webp', $sermon->plain_thumbnail_file_path);
        $this->assertSame('private/thumbs/card.webp', $sermon->card_thumbnail_file_path);
        $this->assertSame('private/thumbs/candidate-overlay.webp', $sermon->thumbnail_metadata?->overlayThumbnailPath);
        $this->assertSame('private/thumbs/candidate-plain.webp', $sermon->thumbnail_candidates[0]['plain_path']);
        $this->assertSame('private/thumbs/candidate-card.webp', $sermon->thumbnail_candidates[0]['card_path']);
        $this->assertSame('private/thumbs/candidate-overlay.webp', $sermon->thumbnail_candidates[0]['overlay_path']);

        foreach (array_keys($sermonPaths + $thumbnailPaths) as $path) {
            Storage::disk('public')->assertMissing($path);
            Storage::disk('local')->assertExists('private/'.$path);
        }
        Storage::disk('transcripts')->assertMissing('transcripts/talk.md');
        Storage::disk('local')->assertExists('private/transcripts/talk.md');
    }

    #[Test]
    public function it_moves_thumbnail_candidate_files_to_private_storage(): void
    {
        Storage::disk('public')->put('sermons/thumbs/candidate-1-plain.webp', 'plain-content');
        Storage::disk('public')->put('sermons/thumbs/candidate-1-card.webp', 'card-content');
        Storage::disk('public')->put('sermons/thumbs/candidate-2-plain.webp', 'plain2-content');

        $metadata = [
            'timestamp' => 60.0,
            'video_duration' => 3600.0,
            'thumbnail_candidates' => [
                [
                    'id' => 'candidate-1',
                    'timestamp' => 60.0,
                    'score' => 0.9,
                    'plain_path' => 'sermons/thumbs/candidate-1-plain.webp',
                    'card_path' => 'sermons/thumbs/candidate-1-card.webp',
                ],
                [
                    'id' => 'candidate-2',
                    'timestamp' => 120.0,
                    'score' => 0.7,
                    'plain_path' => 'sermons/thumbs/candidate-2-plain.webp',
                ],
            ],
        ];

        $sermon = Sermon::withoutEvents(fn (): Sermon => Sermon::factory()->create([
            'thumbnail_metadata' => ThumbnailMetadata::fromArray($metadata),
            'content_type' => SermonContentType::ChildrensTalk,
        ]));

        (new MoveSermonToPrivateStorage($sermon->id))->handle();

        $sermon->refresh();

        $candidates = $sermon->thumbnail_metadata?->thumbnailCandidates ?? [];
        $this->assertCount(2, $candidates);

        $this->assertSame('private/sermons/thumbs/candidate-1-plain.webp', $candidates[0]['plain_path']);
        $this->assertSame('private/sermons/thumbs/candidate-1-card.webp', $candidates[0]['card_path']);
        $this->assertSame('private/sermons/thumbs/candidate-2-plain.webp', $candidates[1]['plain_path']);

        Storage::disk('local')->assertExists('private/sermons/thumbs/candidate-1-plain.webp');
        Storage::disk('local')->assertExists('private/sermons/thumbs/candidate-1-card.webp');
        Storage::disk('local')->assertExists('private/sermons/thumbs/candidate-2-plain.webp');

        Storage::disk('public')->assertMissing('sermons/thumbs/candidate-1-plain.webp');
        Storage::disk('public')->assertMissing('sermons/thumbs/candidate-1-card.webp');
        Storage::disk('public')->assertMissing('sermons/thumbs/candidate-2-plain.webp');
    }

    #[Test]
    public function it_moves_selected_thumbnail_paths_that_are_shared_with_a_candidate(): void
    {
        Storage::disk('public')->put('thumbs/selected-plain.webp', 'plain');
        Storage::disk('public')->put('thumbs/selected-card.webp', 'card');

        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'thumbs/selected-plain.webp',
                'card_thumbnail_path' => 'thumbs/selected-card.webp',
                'selected_thumbnail_candidate_id' => 'selected',
                'thumbnail_candidates' => [[
                    'id' => 'selected',
                    'timestamp' => 30.0,
                    'score' => 0.9,
                    'plain_path' => 'thumbs/selected-plain.webp',
                    'card_path' => 'thumbs/selected-card.webp',
                ]],
            ],
        ]);

        (new MoveSermonToPrivateStorage($sermon->id))->handle();

        $sermon->refresh();

        $this->assertSame('private/thumbs/selected-plain.webp', $sermon->plain_thumbnail_file_path);
        $this->assertSame('private/thumbs/selected-card.webp', $sermon->card_thumbnail_file_path);
        $this->assertSame('private/thumbs/selected-plain.webp', $sermon->thumbnail_candidates[0]['plain_path']);
        $this->assertSame('private/thumbs/selected-card.webp', $sermon->thumbnail_candidates[0]['card_path']);
        Storage::disk('public')->assertMissing('thumbs/selected-plain.webp');
        Storage::disk('public')->assertMissing('thumbs/selected-card.webp');
    }

    #[Test]
    public function it_skips_candidate_files_already_in_private_storage(): void
    {
        Storage::disk('local')->put('private/sermons/thumbs/candidate-1-plain.webp', 'already-private');

        $metadata = [
            'thumbnail_candidates' => [
                [
                    'id' => 'candidate-1',
                    'timestamp' => 60.0,
                    'score' => 0.9,
                    'plain_path' => 'private/sermons/thumbs/candidate-1-plain.webp',
                ],
            ],
        ];

        $sermon = Sermon::factory()->create([
            'thumbnail_metadata' => ThumbnailMetadata::fromArray($metadata),
            'content_type' => SermonContentType::ChildrensTalk,
        ]);

        (new MoveSermonToPrivateStorage($sermon->id))->handle();

        $sermon->refresh();

        $candidates = $sermon->thumbnail_metadata?->thumbnailCandidates ?? [];
        $this->assertSame('private/sermons/thumbs/candidate-1-plain.webp', $candidates[0]['plain_path']);
    }

    #[Test]
    public function it_fails_visibly_when_a_referenced_source_is_missing(): void
    {
        $metadata = [
            'thumbnail_candidates' => [
                [
                    'id' => 'candidate-1',
                    'timestamp' => 60.0,
                    'score' => 0.9,
                    'plain_path' => 'sermons/thumbs/nonexistent.webp',
                ],
            ],
        ];

        $sermon = Sermon::withoutEvents(fn (): Sermon => Sermon::factory()->create([
            'thumbnail_metadata' => ThumbnailMetadata::fromArray($metadata),
            'content_type' => SermonContentType::ChildrensTalk,
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Private-media source is missing');

        (new MoveSermonToPrivateStorage($sermon->id))->handle();
    }

    #[Test]
    public function it_keeps_the_source_and_database_path_when_copying_fails(): void
    {
        $sermon = Sermon::withoutEvents(fn (): Sermon => Sermon::factory()->create([
            'audio_file_path' => 'sermons/audio.mp3',
            'content_type' => SermonContentType::ChildrensTalk,
        ]));
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, 'audio');
        rewind($stream);

        $source = Mockery::mock(FilesystemAdapter::class);
        $source->shouldReceive('exists')->once()->with('sermons/audio.mp3')->andReturnTrue();
        $source->shouldReceive('readStream')->once()->with('sermons/audio.mp3')->andReturn($stream);

        $target = Mockery::mock(FilesystemAdapter::class);
        $target->shouldReceive('exists')->once()->with('private/sermons/audio.mp3')->andReturnFalse();
        $target->shouldReceive('writeStream')->once()->andReturnFalse();
        $target->shouldReceive('delete')->once()->with('private/sermons/audio.mp3')->andReturnTrue();

        Storage::shouldReceive('disk')->with('public')->andReturn($source);
        Storage::shouldReceive('disk')->with('local')->andReturn($target);

        try {
            (new MoveSermonToPrivateStorage($sermon->id))->handle();
            $this->fail('The failed copy should throw.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Unable to write private-media target', $exception->getMessage());
        }

        $this->assertFalse(is_resource($stream));
        $this->assertSame('sermons/audio.mp3', $sermon->fresh()->audio_file_path);
    }

    #[Test]
    public function it_retries_public_source_cleanup_after_the_database_path_is_private(): void
    {
        Storage::disk('local')->put('private/sermons/audio.mp3', 'audio-content');
        Storage::disk('public')->put('sermons/audio.mp3', 'audio-content');

        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'private/sermons/audio.mp3',
            'content_type' => SermonContentType::ChildrensTalk,
        ]);

        (new MoveSermonToPrivateStorage($sermon->id))->handle();

        $this->assertSame('private/sermons/audio.mp3', $sermon->fresh()->audio_file_path);
        Storage::disk('local')->assertExists('private/sermons/audio.mp3');
        Storage::disk('public')->assertMissing('sermons/audio.mp3');
    }

    #[Test]
    public function it_preserves_a_pre_existing_private_target_when_verification_fails(): void
    {
        Storage::disk('public')->put('sermons/shared.mp3', 'new-public-audio');
        Storage::disk('local')->put('private/sermons/shared.mp3', 'existing-private-audio');

        Sermon::withoutEvents(fn (): Sermon => Sermon::factory()->create([
            'audio_file_path' => 'private/sermons/shared.mp3',
        ]));

        $sermon = Sermon::withoutEvents(fn (): Sermon => Sermon::factory()->create([
            'audio_file_path' => 'sermons/shared.mp3',
            'content_type' => SermonContentType::ChildrensTalk,
        ]));

        try {
            (new MoveSermonToPrivateStorage($sermon->id))->handle();
            $this->fail('A mismatched target should fail verification.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('target verification failed', $exception->getMessage());
        }

        $this->assertSame('existing-private-audio', Storage::disk('local')->get('private/sermons/shared.mp3'));
        $this->assertSame('sermons/shared.mp3', $sermon->fresh()->audio_file_path);
        Storage::disk('public')->assertExists('sermons/shared.mp3');
    }

    #[Test]
    public function it_cleans_up_an_earlier_committed_source_when_a_later_asset_fails(): void
    {
        Storage::disk('public')->put('sermons/audio.mp3', 'audio-content');

        $sermon = Sermon::withoutEvents(fn (): Sermon => Sermon::factory()->create([
            'audio_file_path' => 'sermons/audio.mp3',
            'video_file_path' => 'sermons/missing-video.mp4',
            'content_type' => SermonContentType::ChildrensTalk,
        ]));

        try {
            (new MoveSermonToPrivateStorage($sermon->id))->handle();
            $this->fail('The missing later asset should fail the move.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('source is missing', $exception->getMessage());
        }

        $this->assertSame('private/sermons/audio.mp3', $sermon->fresh()->audio_file_path);
        Storage::disk('local')->assertExists('private/sermons/audio.mp3');
        Storage::disk('public')->assertMissing('sermons/audio.mp3');
    }

    #[Test]
    public function it_retains_a_public_source_still_referenced_by_another_sermon(): void
    {
        Storage::disk('public')->put('sermons/shared.mp3', 'shared-audio');

        Sermon::withoutEvents(fn (): Sermon => Sermon::factory()->create([
            'audio_file_path' => 'sermons/shared.mp3',
        ]));

        $sermon = Sermon::withoutEvents(fn (): Sermon => Sermon::factory()->create([
            'audio_file_path' => 'sermons/shared.mp3',
            'content_type' => SermonContentType::ChildrensTalk,
        ]));

        (new MoveSermonToPrivateStorage($sermon->id))->handle();

        $this->assertSame('private/sermons/shared.mp3', $sermon->fresh()->audio_file_path);
        Storage::disk('local')->assertExists('private/sermons/shared.mp3');
        Storage::disk('public')->assertExists('sermons/shared.mp3');
    }

    #[Test]
    public function it_does_nothing_when_sermon_not_found(): void
    {
        $this->expectNotToPerformAssertions();

        (new MoveSermonToPrivateStorage(99999))->handle();
    }
}
