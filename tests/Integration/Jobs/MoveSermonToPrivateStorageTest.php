<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Data\ThumbnailMetadata;
use App\Jobs\MoveSermonToPrivateStorage;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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

        config([
            'thumbnail-generation.storage.disk' => 'public',
            'media-processing.storage.sermon_disk' => 'public',
        ]);
    }

    #[Test]
    public function it_moves_audio_file_to_private_storage(): void
    {
        Storage::disk('public')->put('sermons/audio.mp3', 'audio-content');

        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'sermons/audio.mp3',
        ]);

        (new MoveSermonToPrivateStorage($sermon->id))->handle();

        $sermon->refresh();

        $this->assertSame('private/sermons/audio.mp3', $sermon->audio_file_path);
        Storage::disk('local')->assertExists('private/sermons/audio.mp3');
        Storage::disk('public')->assertMissing('sermons/audio.mp3');
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

        $sermon = Sermon::factory()->create([
            'thumbnail_metadata' => ThumbnailMetadata::fromArray($metadata),
        ]);

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
        ]);

        (new MoveSermonToPrivateStorage($sermon->id))->handle();

        $sermon->refresh();

        $candidates = $sermon->thumbnail_metadata?->thumbnailCandidates ?? [];
        $this->assertSame('private/sermons/thumbs/candidate-1-plain.webp', $candidates[0]['plain_path']);
    }

    #[Test]
    public function it_skips_missing_candidate_files_gracefully(): void
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

        $sermon = Sermon::factory()->create([
            'thumbnail_metadata' => ThumbnailMetadata::fromArray($metadata),
        ]);

        (new MoveSermonToPrivateStorage($sermon->id))->handle();

        $sermon->refresh();

        $candidates = $sermon->thumbnail_metadata?->thumbnailCandidates ?? [];
        // Path remains unchanged — file was missing, nothing was moved
        $this->assertSame('sermons/thumbs/nonexistent.webp', $candidates[0]['plain_path']);
    }

    #[Test]
    public function it_does_nothing_when_sermon_not_found(): void
    {
        $this->expectNotToPerformAssertions();

        (new MoveSermonToPrivateStorage(99999))->handle();
    }
}
