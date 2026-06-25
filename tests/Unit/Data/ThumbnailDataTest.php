<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\ThumbnailMetadata;
use App\Data\ThumbnailMetadataCast;
use App\Data\ThumbnailResult;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ThumbnailDataTest extends TestCase
{
    // ── ThumbnailMetadata ─────────────────────────────────────────────────────

    #[Test]
    public function thumbnail_metadata_creates_from_full_array(): void
    {
        $metadata = ThumbnailMetadata::fromArray([
            'timestamp' => 12.5,
            'video_duration' => 3600.0,
            'video_resolution' => ['width' => 1920, 'height' => 1080],
            'thumbnail_sizes' => ['sm' => 150, 'lg' => 600],
            'generated_at' => '2024-01-01T10:00:00Z',
            'plain_thumbnail_path' => 'thumbs/plain.webp',
            'card_thumbnail_path' => 'thumbs/card.webp',
            'overlay_thumbnail_path' => 'thumbs/overlay.webp',
            'selected_thumbnail_candidate_id' => 'candidate-3',
            'thumbnail_candidates' => [
                [
                    'id' => 'candidate-1',
                    'timestamp' => 100.0,
                    'score' => 0.4567,
                    'card_path' => 'thumbs/candidate-1-card.webp',
                    'overlay_path' => 'thumbs/candidate-1-overlay.webp',
                    'plain_path' => 'thumbs/candidate-1-plain.webp',
                ],
            ],
            'composition_mode' => 'layered_subject',
            'foreground_extraction_method' => 'pixian_api',
            'foreground_bounds' => ['x' => 10, 'y' => 20, 'width' => 30, 'height' => 40],
            'foreground_coverage' => 0.1234,
        ]);

        $this->assertSame(12.5, $metadata->timestamp);
        $this->assertSame(3600.0, $metadata->videoDuration);
        $this->assertSame(['width' => 1920, 'height' => 1080], $metadata->videoResolution);
        $this->assertSame(['sm' => 150, 'lg' => 600], $metadata->thumbnailSizes);
        $this->assertSame('2024-01-01T10:00:00Z', $metadata->generatedAt);
        $this->assertSame('thumbs/plain.webp', $metadata->plainThumbnailPath);
        $this->assertSame('thumbs/card.webp', $metadata->cardThumbnailPath);
        $this->assertSame('thumbs/overlay.webp', $metadata->overlayThumbnailPath);
        $this->assertSame('candidate-3', $metadata->selectedThumbnailCandidateId);
        $this->assertSame('candidate-1', $metadata->thumbnailCandidates[0]['id']);
        $this->assertSame('thumbs/candidate-1-card.webp', $metadata->thumbnailCandidates[0]['card_path']);
        $this->assertSame('layered_subject', $metadata->compositionMode);
        $this->assertSame('pixian_api', $metadata->foregroundExtractionMethod);
        $this->assertSame(['x' => 10, 'y' => 20, 'width' => 30, 'height' => 40], $metadata->foregroundBounds);
        $this->assertSame(0.1234, $metadata->foregroundCoverage);
    }

    #[Test]
    public function thumbnail_metadata_returns_null_for_non_array(): void
    {
        $this->assertNull(ThumbnailMetadata::fromArray(null));
        $this->assertNull(ThumbnailMetadata::fromArray('string'));
    }

    #[Test]
    public function thumbnail_metadata_creates_from_empty_array(): void
    {
        $metadata = ThumbnailMetadata::fromArray([]);

        $this->assertNull($metadata->timestamp);
        $this->assertNull($metadata->videoDuration);
        $this->assertSame([], $metadata->videoResolution);
        $this->assertSame([], $metadata->thumbnailSizes);
    }

    #[Test]
    public function thumbnail_metadata_round_trips_via_to_array(): void
    {
        $input = [
            'timestamp' => 30.0,
            'video_duration' => 1800.0,
            'plain_thumbnail_path' => 'thumbs/plain.webp',
            'card_thumbnail_path' => 'thumbs/card.webp',
            'selected_thumbnail_candidate_id' => 'candidate-2',
            'thumbnail_candidates' => [
                [
                    'id' => 'candidate-2',
                    'timestamp' => 240.0,
                    'score' => 0.8123,
                    'card_path' => 'thumbs/candidate-2-card.webp',
                    'overlay_path' => 'thumbs/candidate-2-overlay.webp',
                    'plain_path' => 'thumbs/candidate-2-plain.webp',
                ],
            ],
            'composition_mode' => 'flat_fallback',
        ];

        $output = ThumbnailMetadata::fromArray($input)->toArray();

        $this->assertSame(30.0, $output['timestamp']);
        $this->assertSame(1800.0, $output['video_duration']);
        $this->assertSame('thumbs/plain.webp', $output['plain_thumbnail_path']);
        $this->assertSame('thumbs/card.webp', $output['card_thumbnail_path']);
        $this->assertSame('candidate-2', $output['selected_thumbnail_candidate_id']);
        $this->assertSame('thumbs/candidate-2-card.webp', $output['thumbnail_candidates'][0]['card_path']);
        $this->assertSame('thumbs/candidate-2-overlay.webp', $output['thumbnail_candidates'][0]['overlay_path']);
        $this->assertSame('flat_fallback', $output['composition_mode']);
    }

    #[Test]
    public function thumbnail_metadata_accepts_plain_only_candidates(): void
    {
        $metadata = ThumbnailMetadata::fromArray([
            'thumbnail_candidates' => [
                [
                    'id' => 'candidate-2',
                    'timestamp' => 240.0,
                    'score' => 0.8123,
                    'plain_path' => 'thumbs/candidate-2-plain.webp',
                ],
            ],
        ]);

        $this->assertCount(1, $metadata->thumbnailCandidates);
        $this->assertArrayNotHasKey('overlay_path', $metadata->thumbnailCandidates[0]);
        $this->assertSame('thumbs/candidate-2-plain.webp', $metadata->thumbnailCandidates[0]['plain_path']);
    }

    #[Test]
    public function thumbnail_metadata_omits_null_fields_from_to_array(): void
    {
        $output = ThumbnailMetadata::fromArray([])->toArray();

        $this->assertArrayNotHasKey('timestamp', $output);
        $this->assertArrayNotHasKey('plain_thumbnail_path', $output);
    }

    #[Test]
    public function thumbnail_metadata_to_array_clears_value_that_was_previously_set_in_raw(): void
    {
        // Simulate a record that originally had a plain_thumbnail_path, then the
        // typed property was set to null (e.g. path was cleared after migration).
        // toArray() must write null for that key so the caller can persist the cleared value.
        $metadata = ThumbnailMetadata::fromArray([
            'timestamp' => 30.0,
            'plain_thumbnail_path' => 'old/path.webp',
        ]);

        // Build a new instance with null plainThumbnailPath but the original raw
        $cleared = new ThumbnailMetadata(
            timestamp: $metadata->timestamp,
            videoDuration: $metadata->videoDuration,
            videoResolution: $metadata->videoResolution,
            thumbnailSizes: $metadata->thumbnailSizes,
            generatedAt: $metadata->generatedAt,
            plainThumbnailPath: null,
            cardThumbnailPath: $metadata->cardThumbnailPath,
            overlayThumbnailPath: $metadata->overlayThumbnailPath,
            selectedThumbnailCandidateId: $metadata->selectedThumbnailCandidateId,
            thumbnailCandidates: $metadata->thumbnailCandidates,
            compositionMode: $metadata->compositionMode,
            foregroundExtractionMethod: $metadata->foregroundExtractionMethod,
            foregroundBounds: $metadata->foregroundBounds,
            foregroundCoverage: $metadata->foregroundCoverage,
            raw: $metadata->toArray(),
        );

        $output = $cleared->toArray();

        $this->assertArrayHasKey('plain_thumbnail_path', $output);
        $this->assertNull($output['plain_thumbnail_path']);
    }

    // ── ThumbnailMetadataCast ─────────────────────────────────────────────────

    #[Test]
    public function cast_get_deserialises_json_to_thumbnail_metadata(): void
    {
        $cast = new ThumbnailMetadataCast;
        $model = $this->createStub(Model::class);

        $json = json_encode(['timestamp' => 5.0, 'video_duration' => 600.0]);
        $result = $cast->get($model, 'thumbnail_metadata', $json, []);

        $this->assertInstanceOf(ThumbnailMetadata::class, $result);
        $this->assertSame(5.0, $result->timestamp);
    }

    #[Test]
    public function cast_get_returns_null_for_empty_value(): void
    {
        $cast = new ThumbnailMetadataCast;
        $model = $this->createStub(Model::class);

        $this->assertNull($cast->get($model, 'thumbnail_metadata', '', []));
        $this->assertNull($cast->get($model, 'thumbnail_metadata', null, []));
    }

    #[Test]
    public function cast_set_serialises_thumbnail_metadata_to_json(): void
    {
        $cast = new ThumbnailMetadataCast;
        $model = $this->createStub(Model::class);

        $metadata = ThumbnailMetadata::fromArray(['timestamp' => 10.5, 'video_duration' => 900.0]);
        $result = $cast->set($model, 'thumbnail_metadata', $metadata, []);

        $this->assertIsString($result);
        $decoded = json_decode($result, true);
        $this->assertSame(10.5, $decoded['timestamp']);
    }

    #[Test]
    public function cast_set_returns_null_for_null(): void
    {
        $cast = new ThumbnailMetadataCast;
        $model = $this->createStub(Model::class);

        $this->assertNull($cast->set($model, 'thumbnail_metadata', null, []));
    }

    #[Test]
    public function cast_round_trips_thumbnail_metadata(): void
    {
        $cast = new ThumbnailMetadataCast;
        $model = $this->createStub(Model::class);

        $original = ThumbnailMetadata::fromArray([
            'timestamp' => 45.0,
            'video_duration' => 3600.0,
            'plain_thumbnail_path' => 'thumbs/plain.webp',
            'selected_thumbnail_candidate_id' => 'candidate-4',
            'thumbnail_candidates' => [
                [
                    'id' => 'candidate-4',
                    'timestamp' => 420.0,
                    'score' => 0.9123,
                    'overlay_path' => 'thumbs/candidate-4-overlay.webp',
                    'plain_path' => 'thumbs/candidate-4-plain.webp',
                ],
            ],
        ]);

        $json = $cast->set($model, 'thumbnail_metadata', $original, []);
        $restored = $cast->get($model, 'thumbnail_metadata', $json, []);

        $this->assertSame(45.0, $restored->timestamp);
        $this->assertSame('thumbs/plain.webp', $restored->plainThumbnailPath);
        $this->assertSame('candidate-4', $restored->selectedThumbnailCandidateId);
        $this->assertSame('thumbs/candidate-4-plain.webp', $restored->selectedCandidate()['plain_path']);
    }

    // ── ThumbnailResult ───────────────────────────────────────────────────────

    #[Test]
    public function thumbnail_result_success_factory(): void
    {
        $result = ThumbnailResult::success('thumbs/output.webp', ['size' => 1024]);

        $this->assertTrue($result->success);
        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isFailed());
        $this->assertSame('thumbs/output.webp', $result->getThumbnailPath());
        $this->assertSame(['size' => 1024], $result->getMetadata());
    }

    #[Test]
    public function thumbnail_result_failed_factory(): void
    {
        $result = ThumbnailResult::failed('FFmpeg not found');

        $this->assertFalse($result->success);
        $this->assertFalse($result->isSuccess());
        $this->assertTrue($result->isFailed());
        $this->assertNull($result->getThumbnailPath());
        $this->assertSame('FFmpeg not found', $result->getErrorMessage());
    }

    #[Test]
    public function thumbnail_result_skipped_factory(): void
    {
        $result = ThumbnailResult::skipped('No video track');

        $this->assertFalse($result->success);
        $this->assertTrue($result->isSkipped());
        $this->assertSame('No video track', $result->getErrorMessage());
    }

    #[Test]
    public function thumbnail_result_get_metadata_value_returns_default_for_missing_key(): void
    {
        $result = ThumbnailResult::success('path.webp', ['width' => 1920]);

        $this->assertSame(1920, $result->getMetadataValue('width'));
        $this->assertNull($result->getMetadataValue('height'));
        $this->assertSame('default', $result->getMetadataValue('height', 'default'));
    }
}
