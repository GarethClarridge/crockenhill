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
    public function it_replaces_a_stale_unreferenced_private_target_and_heals(): void
    {
        Storage::disk('public')->put('sermons/audio.mp3', 'full-audio-content');
        // A crashed earlier attempt left a partial write that no sermon row references.
        Storage::disk('local')->put('private/sermons/audio.mp3', 'partial');

        $sermon = Sermon::withoutEvents(fn (): Sermon => Sermon::factory()->create([
            'audio_file_path' => 'sermons/audio.mp3',
            'content_type' => SermonContentType::ChildrensTalk,
        ]));

        (new MoveSermonToPrivateStorage($sermon->id))->handle();

        $this->assertSame('private/sermons/audio.mp3', $sermon->fresh()->audio_file_path);
        $this->assertSame('full-audio-content', Storage::disk('local')->get('private/sermons/audio.mp3'));
        Storage::disk('public')->assertMissing('sermons/audio.mp3');
    }

    #[Test]
    public function it_moves_remaining_assets_when_an_earlier_asset_fails(): void
    {
        Storage::disk('public')->put('sermons/video.mp4', 'video-content');

        $sermon = Sermon::withoutEvents(fn (): Sermon => Sermon::factory()->create([
            'audio_file_path' => 'sermons/missing-audio.mp3',
            'video_file_path' => 'sermons/video.mp4',
            'content_type' => SermonContentType::ChildrensTalk,
        ]));

        try {
            (new MoveSermonToPrivateStorage($sermon->id))->handle();
            $this->fail('The missing audio source should fail the move.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('source is missing', $exception->getMessage());
        }

        $this->assertSame('private/sermons/video.mp4', $sermon->fresh()->video_file_path);
        Storage::disk('local')->assertExists('private/sermons/video.mp4');
        Storage::disk('public')->assertMissing('sermons/video.mp4');
    }

    #[Test]
    public function it_preserves_a_referenced_pre_existing_private_target_when_verification_fails(): void
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

    #[Test]
    public function it_moves_every_private_asset_back_onto_its_kind_disk(): void
    {
        $sermonPaths = ['sermons/audio.mp3', 'sermons/video.mp4'];
        $thumbnailPaths = [
            'thumbs/primary.webp',
            'thumbs/plain.webp',
            'thumbs/card.webp',
            'thumbs/candidate-plain.webp',
            'thumbs/candidate-card.webp',
            'thumbs/candidate-overlay.webp',
        ];

        foreach ([...$sermonPaths, ...$thumbnailPaths, 'transcripts/talk.md'] as $path) {
            Storage::disk('local')->put('private/'.$path, 'contents-of-'.$path);
        }

        $sermon = Sermon::withoutEvents(fn (): Sermon => Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'audio_file_path' => 'private/sermons/audio.mp3',
            'video_file_path' => 'private/sermons/video.mp4',
            'transcript_file_path' => 'private/transcripts/talk.md',
            'thumbnail_file_path' => 'private/thumbs/primary.webp',
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'private/thumbs/plain.webp',
                'card_thumbnail_path' => 'private/thumbs/card.webp',
                'overlay_thumbnail_path' => 'private/thumbs/candidate-overlay.webp',
                'thumbnail_candidates' => [[
                    'id' => 'candidate-1',
                    'timestamp' => 10.0,
                    'score' => 0.9,
                    'plain_path' => 'private/thumbs/candidate-plain.webp',
                    'card_path' => 'private/thumbs/candidate-card.webp',
                    'overlay_path' => 'private/thumbs/candidate-overlay.webp',
                ]],
            ],
        ]));

        (new MoveSermonToPrivateStorage($sermon->id, toPrivate: false))->handle();

        $sermon->refresh();

        $this->assertSame('sermons/audio.mp3', $sermon->audio_file_path);
        $this->assertSame('sermons/video.mp4', $sermon->video_file_path);
        $this->assertSame('transcripts/talk.md', $sermon->transcript_file_path);
        $this->assertSame('thumbs/primary.webp', $sermon->thumbnail_file_path);
        $this->assertSame('thumbs/plain.webp', $sermon->plain_thumbnail_file_path);
        $this->assertSame('thumbs/card.webp', $sermon->card_thumbnail_file_path);
        $this->assertSame('thumbs/candidate-overlay.webp', $sermon->thumbnail_metadata?->overlayThumbnailPath);
        $this->assertSame('thumbs/candidate-plain.webp', $sermon->thumbnail_candidates[0]['plain_path']);
        $this->assertSame('thumbs/candidate-card.webp', $sermon->thumbnail_candidates[0]['card_path']);
        $this->assertSame('thumbs/candidate-overlay.webp', $sermon->thumbnail_candidates[0]['overlay_path']);

        // Each kind lands on its own configured disk, not on one shared disk.
        foreach ([...$sermonPaths, ...$thumbnailPaths] as $path) {
            Storage::disk('public')->assertExists($path);
            Storage::disk('local')->assertMissing('private/'.$path);
        }

        Storage::disk('transcripts')->assertExists('transcripts/talk.md');
        Storage::disk('local')->assertMissing('private/transcripts/talk.md');
    }

    /**
     * The reverse of `it_retains_a_public_source_still_referenced_by_another_sermon`.
     * This is the case `referencedAssetIndex()` used to get wrong: it keyed every
     * referenced path against its kind's public disk, so a row holding a
     * `private/…` path was indexed under `public|private/…` and the `local|private/…`
     * lookup here could never match — deleting a second sermon's only copy.
     */
    #[Test]
    public function it_retains_a_private_source_still_referenced_by_another_sermon(): void
    {
        Storage::disk('local')->put('private/sermons/shared.mp3', 'shared-audio');

        Sermon::withoutEvents(fn (): Sermon => Sermon::factory()->create([
            'audio_file_path' => 'private/sermons/shared.mp3',
        ]));

        $sermon = Sermon::withoutEvents(fn (): Sermon => Sermon::factory()->create([
            'audio_file_path' => 'private/sermons/shared.mp3',
            'content_type' => SermonContentType::ChildrensTalk,
        ]));

        (new MoveSermonToPrivateStorage($sermon->id, toPrivate: false))->handle();

        $this->assertSame('sermons/shared.mp3', $sermon->fresh()->audio_file_path);
        Storage::disk('public')->assertExists('sermons/shared.mp3');
        Storage::disk('local')->assertExists('private/sermons/shared.mp3');
    }

    #[Test]
    public function it_resumes_a_partially_publicised_talk(): void
    {
        // Audio was already committed on a previous run; video was not.
        Storage::disk('public')->put('sermons/audio.mp3', 'audio-content');
        Storage::disk('local')->put('private/sermons/audio.mp3', 'audio-content');
        Storage::disk('local')->put('private/sermons/video.mp4', 'video-content');

        $sermon = Sermon::withoutEvents(fn (): Sermon => Sermon::factory()->create([
            'audio_file_path' => 'sermons/audio.mp3',
            'video_file_path' => 'private/sermons/video.mp4',
            'content_type' => SermonContentType::ChildrensTalk,
        ]));

        (new MoveSermonToPrivateStorage($sermon->id, toPrivate: false))->handle();

        $sermon->refresh();

        $this->assertSame('sermons/audio.mp3', $sermon->audio_file_path);
        $this->assertSame('sermons/video.mp4', $sermon->video_file_path);
        Storage::disk('public')->assertExists('sermons/video.mp4');
        Storage::disk('local')->assertMissing('private/sermons/audio.mp3');
        Storage::disk('local')->assertMissing('private/sermons/video.mp4');
    }

    #[Test]
    public function it_leaves_private_sources_in_place_when_deletion_is_disabled(): void
    {
        Storage::disk('local')->put('private/sermons/audio.mp3', 'audio-content');

        $sermon = Sermon::withoutEvents(fn (): Sermon => Sermon::factory()->create([
            'audio_file_path' => 'private/sermons/audio.mp3',
            'content_type' => SermonContentType::ChildrensTalk,
        ]));

        (new MoveSermonToPrivateStorage($sermon->id, toPrivate: false, deleteSource: false))->handle();

        $this->assertSame('sermons/audio.mp3', $sermon->fresh()->audio_file_path);
        Storage::disk('public')->assertExists('sermons/audio.mp3');
        // The byte-identical rollback copy survives the copy-only pass.
        Storage::disk('local')->assertExists('private/sermons/audio.mp3');
    }

    #[Test]
    public function it_deletes_a_private_source_only_after_the_committed_copy_verifies(): void
    {
        Storage::disk('public')->put('sermons/audio.mp3', 'audio-content');
        Storage::disk('local')->put('private/sermons/audio.mp3', 'audio-content');

        $sermon = Sermon::withoutEvents(fn (): Sermon => Sermon::factory()->create([
            'audio_file_path' => 'sermons/audio.mp3',
            'content_type' => SermonContentType::ChildrensTalk,
        ]));

        (new MoveSermonToPrivateStorage($sermon->id, toPrivate: false, deleteSource: true))->handle();

        $this->assertSame('sermons/audio.mp3', $sermon->fresh()->audio_file_path);
        Storage::disk('public')->assertExists('sermons/audio.mp3');
        Storage::disk('local')->assertMissing('private/sermons/audio.mp3');
    }

    #[Test]
    public function it_refuses_to_delete_a_private_source_when_the_committed_copy_is_missing(): void
    {
        // The row claims the copy is committed, but the object is not there.
        Storage::disk('local')->put('private/sermons/audio.mp3', 'audio-content');

        $sermon = Sermon::withoutEvents(fn (): Sermon => Sermon::factory()->create([
            'audio_file_path' => 'sermons/audio.mp3',
            'content_type' => SermonContentType::ChildrensTalk,
        ]));

        try {
            (new MoveSermonToPrivateStorage($sermon->id, toPrivate: false, deleteSource: true))->handle();
            $this->fail('A missing committed target should fail before any deletion.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('target is missing', $exception->getMessage());
        }

        Storage::disk('local')->assertExists('private/sermons/audio.mp3');
    }

    #[Test]
    public function it_aborts_the_commit_when_the_path_changes_concurrently(): void
    {
        $sermon = Sermon::withoutEvents(fn (): Sermon => Sermon::factory()->create([
            'audio_file_path' => 'private/sermons/audio.mp3',
            'content_type' => SermonContentType::ChildrensTalk,
        ]));

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, 'audio-content');
        rewind($stream);

        $source = Mockery::mock(FilesystemAdapter::class);
        $source->shouldReceive('exists')->with('private/sermons/audio.mp3')->andReturnTrue();
        $source->shouldReceive('readStream')->with('private/sermons/audio.mp3')->andReturn($stream);
        $source->shouldReceive('size')->with('private/sermons/audio.mp3')->andReturn(13);

        $target = Mockery::mock(FilesystemAdapter::class);
        $target->shouldReceive('exists')->with('sermons/audio.mp3')->andReturn(false, true);
        $target->shouldReceive('size')->with('sermons/audio.mp3')->andReturn(13);
        // Another writer repoints the row while this copy is in flight.
        $target->shouldReceive('writeStream')->once()->andReturnUsing(
            function () use ($sermon): bool {
                Sermon::withoutEvents(fn () => Sermon::query()
                    ->whereKey($sermon->id)
                    ->update(['audio_file_path' => 'sermons/reprocessed.mp3']));

                return true;
            },
        );

        Storage::shouldReceive('disk')->with('local')->andReturn($source);
        Storage::shouldReceive('disk')->with('public')->andReturn($target);

        try {
            (new MoveSermonToPrivateStorage($sermon->id, toPrivate: false))->handle();
            $this->fail('A concurrent path change should abort the commit.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('changed concurrently', $exception->getMessage());
        }

        $this->assertSame('sermons/reprocessed.mp3', $sermon->fresh()->audio_file_path);
    }
}
