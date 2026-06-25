<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\ProcessingId3Metadata;
use App\Data\ProcessingManualReviewMetadata;
use App\Data\ProcessingMetadata;
use App\Data\ProcessingMetadataCast;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessingMetadataDataTest extends TestCase
{
    // ── ProcessingMetadata::fromArray ─────────────────────────────────────────

    #[Test]
    public function it_creates_from_full_array(): void
    {
        $data = ProcessingMetadata::fromArray([
            'id3_metadata' => [
                'title' => 'Sermon Title',
                'preacher' => 'John Smith',
                'series' => 'Romans',
                'reference' => 'Romans 8:1',
            ],
            'manual_review' => [
                'status' => 'pending',
                'reason_code' => 'low_confidence',
                'reason_message' => 'Score below threshold',
                'flagged_at' => '2024-01-01T10:00:00Z',
            ],
            'speaker_identification' => ['speaker_id' => 42],
        ]);

        $this->assertInstanceOf(ProcessingId3Metadata::class, $data->id3Metadata);
        $this->assertSame('Sermon Title', $data->id3Metadata->title);
        $this->assertSame('John Smith', $data->id3Metadata->preacher);

        $this->assertInstanceOf(ProcessingManualReviewMetadata::class, $data->manualReview);
        $this->assertSame('pending', $data->manualReview->status);

        $this->assertSame(['speaker_id' => 42], $data->speakerIdentification);
    }

    #[Test]
    public function it_creates_from_empty_array(): void
    {
        $data = ProcessingMetadata::fromArray([]);

        $this->assertNull($data->id3Metadata);
        $this->assertNull($data->manualReview);
        $this->assertNull($data->speakerIdentification);
    }

    #[Test]
    public function it_creates_from_null(): void
    {
        $data = ProcessingMetadata::fromArray(null);

        $this->assertNull($data->id3Metadata);
        $this->assertNull($data->manualReview);
    }

    // ── toArray round-trip ────────────────────────────────────────────────────

    #[Test]
    public function it_round_trips_via_to_array(): void
    {
        $original = [
            'id3_metadata' => ['title' => 'Test', 'preacher' => 'Jane'],
            'manual_review' => ['status' => 'confirmed'],
        ];

        $data = ProcessingMetadata::fromArray($original);
        $exported = $data->toArray();

        $this->assertSame('Test', $exported['id3_metadata']['title']);
        $this->assertSame('confirmed', $exported['manual_review']['status']);
    }

    #[Test]
    public function it_preserves_unknown_raw_fields_in_to_array(): void
    {
        $data = ProcessingMetadata::fromArray([
            'custom_field' => 'custom_value',
        ]);

        $this->assertSame('custom_value', $data->toArray()['custom_field']);
    }

    // ── ArrayAccess / JsonSerializable ────────────────────────────────────────

    #[Test]
    public function it_throws_on_array_write(): void
    {
        $data = ProcessingMetadata::fromArray([]);

        $this->expectException(LogicException::class);
        $data['key'] = 'value'; // @phpstan-ignore-line
    }

    #[Test]
    public function it_serialises_to_json(): void
    {
        $data = ProcessingMetadata::fromArray(['id3_metadata' => ['title' => 'Hello']]);

        $json = json_encode($data);
        $decoded = json_decode($json, true);

        $this->assertSame('Hello', $decoded['id3_metadata']['title']);
    }

    // ── ProcessingId3Metadata ─────────────────────────────────────────────────

    #[Test]
    public function id3_metadata_creates_from_array(): void
    {
        $id3 = ProcessingId3Metadata::fromArray([
            'title' => ' Trimmed Title ',
            'preacher' => 'Rev Brown',
            'series' => null,
            'reference' => '',
        ]);

        $this->assertSame('Trimmed Title', $id3->title);
        $this->assertSame('Rev Brown', $id3->preacher);
        $this->assertNull($id3->series);
        $this->assertNull($id3->reference); // empty string → null
    }

    #[Test]
    public function id3_metadata_returns_null_for_non_array(): void
    {
        $this->assertNull(ProcessingId3Metadata::fromArray(null));
        $this->assertNull(ProcessingId3Metadata::fromArray('string'));
    }

    #[Test]
    public function id3_metadata_round_trips_to_array(): void
    {
        $id3 = ProcessingId3Metadata::fromArray([
            'title' => 'My Sermon',
            'preacher' => 'Pastor Bob',
        ]);

        $arr = $id3->toArray();
        $this->assertSame('My Sermon', $arr['title']);
        $this->assertSame('Pastor Bob', $arr['preacher']);
    }

    // ── ProcessingManualReviewMetadata ────────────────────────────────────────

    #[Test]
    public function manual_review_metadata_creates_from_full_array(): void
    {
        $metadata = ProcessingManualReviewMetadata::fromArray([
            'status' => 'flagged',
            'reason_code' => 'ambiguous',
            'reason_message' => 'Could not determine speaker',
            'flagged_at' => '2024-01-01T12:00:00Z',
            'speech_segments' => [
                [
                    'segment_id' => 1,
                    'start_time' => 0.0,
                    'end_time' => 600.0,
                    'duration' => 600.0,
                ],
            ],
            'confirmed_segment_id' => 1,
            'confirmed_by_user_id' => 5,
            'confirmed_at' => '2024-01-02T09:00:00Z',
        ]);

        $this->assertSame('flagged', $metadata->status);
        $this->assertCount(1, $metadata->speechSegments);
        $this->assertSame(1, $metadata->speechSegments[0]['segment_id']);
        $this->assertSame(1, $metadata->confirmedSegmentId);
        $this->assertSame(5, $metadata->confirmedByUserId);
    }

    #[Test]
    public function manual_review_metadata_filters_invalid_speech_segments(): void
    {
        $metadata = ProcessingManualReviewMetadata::fromArray([
            'speech_segments' => [
                ['segment_id' => 1, 'start_time' => 0.0, 'end_time' => 300.0, 'duration' => 300.0],
                ['segment_id' => 2], // missing required fields
                'not_an_array',
            ],
        ]);

        $this->assertCount(1, $metadata->speechSegments);
    }

    #[Test]
    public function manual_review_metadata_returns_null_for_non_array(): void
    {
        $this->assertNull(ProcessingManualReviewMetadata::fromArray(null));
    }

    // ── ProcessingMetadataCast ────────────────────────────────────────────────

    #[Test]
    public function cast_get_deserialises_json_string(): void
    {
        $cast = new ProcessingMetadataCast;
        $model = $this->createStub(Model::class);

        $json = json_encode(['id3_metadata' => ['title' => 'From Cast']]);
        $result = $cast->get($model, 'metadata', $json, []);

        $this->assertInstanceOf(ProcessingMetadata::class, $result);
        $this->assertSame('From Cast', $result->id3Metadata->title);
    }

    #[Test]
    public function cast_get_returns_empty_metadata_for_null(): void
    {
        $cast = new ProcessingMetadataCast;
        $model = $this->createStub(Model::class);

        $result = $cast->get($model, 'metadata', null, []);

        $this->assertInstanceOf(ProcessingMetadata::class, $result);
        $this->assertNull($result->id3Metadata);
    }

    #[Test]
    public function cast_set_serialises_processing_metadata_to_json(): void
    {
        $cast = new ProcessingMetadataCast;
        $model = $this->createStub(Model::class);

        $metadata = ProcessingMetadata::fromArray(['id3_metadata' => ['title' => 'Serialised']]);
        $result = $cast->set($model, 'metadata', $metadata, []);

        $this->assertIsString($result);
        $decoded = json_decode($result, true);
        $this->assertSame('Serialised', $decoded['id3_metadata']['title']);
    }

    #[Test]
    public function cast_set_serialises_plain_array(): void
    {
        $cast = new ProcessingMetadataCast;
        $model = $this->createStub(Model::class);

        $result = $cast->set($model, 'metadata', ['key' => 'val'], []);

        $this->assertSame('{"key":"val"}', $result);
    }

    #[Test]
    public function cast_set_returns_null_for_null(): void
    {
        $cast = new ProcessingMetadataCast;
        $model = $this->createStub(Model::class);

        $this->assertNull($cast->set($model, 'metadata', null, []));
    }

    #[Test]
    public function cast_round_trips_processing_metadata(): void
    {
        $cast = new ProcessingMetadataCast;
        $model = $this->createStub(Model::class);

        $original = ProcessingMetadata::fromArray([
            'id3_metadata' => ['title' => 'Round Trip', 'preacher' => 'Alice'],
            'manual_review' => ['status' => 'confirmed'],
        ]);

        $json = $cast->set($model, 'metadata', $original, []);
        $restored = $cast->get($model, 'metadata', $json, []);

        $this->assertSame('Round Trip', $restored->id3Metadata->title);
        $this->assertSame('confirmed', $restored->manualReview->status);
    }
}
