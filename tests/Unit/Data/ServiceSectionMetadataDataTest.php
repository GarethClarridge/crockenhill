<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\ChildrensTalkSpeakerMetadata;
use App\Data\SectionOosAlignment;
use App\Data\SectionPublicationMetadata;
use App\Data\ServiceSectionMetadata;
use App\Data\ServiceSectionMetadataCast;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceSectionMetadataDataTest extends TestCase
{
    // ── ServiceSectionMetadata ────────────────────────────────────────────────

    #[Test]
    public function it_creates_from_full_array(): void
    {
        $metadata = ServiceSectionMetadata::fromArray([
            'confidence_level' => 'high',
            'classification_mode' => 'auto',
            'confidence_source' => 'rms_analysis',
            'confidence_score' => 0.95,
            'review_reason' => 'ambiguous',
            'review_flags' => ['flag_a', 'flag_b'],
            'transcript' => 'The sermon transcript text...',
            'song_id' => 42,
            'reading_reference' => 'John 3:16',
        ]);

        $this->assertSame('high', $metadata->confidenceLevel);
        $this->assertSame('auto', $metadata->classificationMode);
        $this->assertSame('rms_analysis', $metadata->confidenceSource);
        $this->assertSame(0.95, $metadata->confidenceScore);
        $this->assertSame('ambiguous', $metadata->reviewReason);
        $this->assertSame(['flag_a', 'flag_b'], $metadata->reviewFlags);
        $this->assertSame('The sermon transcript text...', $metadata->transcript);
        $this->assertSame(42, $metadata->songId);
        $this->assertSame('John 3:16', $metadata->readingReference);
    }

    #[Test]
    public function it_creates_from_empty_array(): void
    {
        $metadata = ServiceSectionMetadata::fromArray([]);

        $this->assertNull($metadata->confidenceLevel);
        $this->assertNull($metadata->songId);
        $this->assertSame([], $metadata->reviewFlags);
        $this->assertNull($metadata->oosAlignment);
        $this->assertNull($metadata->childrensTalkSpeaker);
        $this->assertNull($metadata->publication);
    }

    #[Test]
    public function it_always_returns_self_even_for_null_input(): void
    {
        $metadata = ServiceSectionMetadata::fromArray(null);

        $this->assertInstanceOf(ServiceSectionMetadata::class, $metadata);
    }

    #[Test]
    public function it_deserialises_nested_oos_alignment(): void
    {
        $metadata = ServiceSectionMetadata::fromArray([
            'oos_alignment' => [
                'song_match_type' => 'exact',
                'song_match_score' => 0.98,
            ],
        ]);

        $this->assertInstanceOf(SectionOosAlignment::class, $metadata->oosAlignment);
        $this->assertSame('exact', $metadata->oosAlignment->raw['song_match_type']);
    }

    #[Test]
    public function it_deserialises_nested_childrens_talk_speaker(): void
    {
        $metadata = ServiceSectionMetadata::fromArray([
            'childrens_talk_speaker' => [
                'reviewed' => ['preacher_id' => 5, 'preacher_name' => 'Jane', 'source' => 'manual', 'confidence' => null],
            ],
        ]);

        $this->assertInstanceOf(ChildrensTalkSpeakerMetadata::class, $metadata->childrensTalkSpeaker);
    }

    #[Test]
    public function it_deserialises_nested_publication(): void
    {
        $metadata = ServiceSectionMetadata::fromArray([
            'publication' => ['approved_signature' => 'admin@example.com'],
        ]);

        $this->assertInstanceOf(SectionPublicationMetadata::class, $metadata->publication);
        $this->assertSame('admin@example.com', $metadata->publication->approvedSignature);
    }

    #[Test]
    public function it_round_trips_via_to_array(): void
    {
        $metadata = ServiceSectionMetadata::fromArray([
            'confidence_level' => 'medium',
            'song_id' => 10,
            'review_flags' => ['needs_check'],
        ]);

        $arr = $metadata->toArray();
        $this->assertSame('medium', $arr['confidence_level']);
        $this->assertSame(10, $arr['song_id']);
        $this->assertSame(['needs_check'], $arr['review_flags']);
    }

    // ── ServiceSectionMetadataCast ────────────────────────────────────────────

    #[Test]
    public function cast_get_deserialises_json(): void
    {
        $cast = new ServiceSectionMetadataCast;
        $model = $this->createStub(Model::class);

        $json = json_encode(['confidence_level' => 'high', 'song_id' => 7]);
        $result = $cast->get($model, 'metadata', $json, []);

        $this->assertInstanceOf(ServiceSectionMetadata::class, $result);
        $this->assertSame('high', $result->confidenceLevel);
        $this->assertSame(7, $result->songId);
    }

    #[Test]
    public function cast_get_returns_empty_metadata_for_null(): void
    {
        $cast = new ServiceSectionMetadataCast;
        $model = $this->createStub(Model::class);

        $result = $cast->get($model, 'metadata', null, []);
        $this->assertInstanceOf(ServiceSectionMetadata::class, $result);
    }

    #[Test]
    public function cast_set_serialises_to_json(): void
    {
        $cast = new ServiceSectionMetadataCast;
        $model = $this->createStub(Model::class);

        $metadata = ServiceSectionMetadata::fromArray(['confidence_level' => 'low', 'song_id' => 3]);
        $result = $cast->set($model, 'metadata', $metadata, []);

        $this->assertIsString($result);
        $decoded = json_decode($result, true);
        $this->assertSame('low', $decoded['confidence_level']);
    }

    #[Test]
    public function cast_set_returns_null_for_null(): void
    {
        $cast = new ServiceSectionMetadataCast;
        $model = $this->createStub(Model::class);

        $this->assertNull($cast->set($model, 'metadata', null, []));
    }

    #[Test]
    public function cast_round_trips_metadata(): void
    {
        $cast = new ServiceSectionMetadataCast;
        $model = $this->createStub(Model::class);

        $original = ServiceSectionMetadata::fromArray([
            'confidence_level' => 'high',
            'song_id' => 99,
            'review_flags' => ['double_check'],
        ]);

        $json = $cast->set($model, 'metadata', $original, []);
        $restored = $cast->get($model, 'metadata', $json, []);

        $this->assertSame('high', $restored->confidenceLevel);
        $this->assertSame(99, $restored->songId);
        $this->assertSame(['double_check'], $restored->reviewFlags);
    }

    // ── ChildrensTalkSpeakerMetadata ──────────────────────────────────────────

    #[Test]
    public function childrens_talk_speaker_creates_from_array(): void
    {
        $metadata = ChildrensTalkSpeakerMetadata::fromArray([
            'predicted' => ['preacher_id' => 1, 'preacher_name' => 'Bob', 'confidence' => 0.8],
            'reviewed' => ['preacher_id' => 2, 'preacher_name' => 'Alice', 'source' => 'manual', 'confidence' => null],
        ]);

        $this->assertSame(['preacher_id' => 1, 'preacher_name' => 'Bob', 'confidence' => 0.8], $metadata->predicted);
        $this->assertSame(['preacher_id' => 2, 'preacher_name' => 'Alice', 'source' => 'manual', 'confidence' => null], $metadata->reviewed);
    }

    #[Test]
    public function childrens_talk_speaker_returns_null_for_non_array(): void
    {
        $this->assertNull(ChildrensTalkSpeakerMetadata::fromArray(null));
    }

    #[Test]
    public function childrens_talk_speaker_publication_speaker_returns_reviewed_data(): void
    {
        $metadata = ChildrensTalkSpeakerMetadata::fromArray([
            'reviewed' => ['preacher_id' => 5, 'preacher_name' => 'Jane Doe', 'source' => 'manual', 'confidence' => 0.9],
        ]);

        $speaker = $metadata->publicationSpeaker();

        $this->assertNotNull($speaker);
        $this->assertSame(5, $speaker['preacher_id']);
        $this->assertSame('Jane Doe', $speaker['preacher_name']);
        $this->assertSame('manual', $speaker['source']);
        $this->assertSame(0.9, $speaker['confidence']);
    }

    #[Test]
    public function childrens_talk_speaker_publication_speaker_returns_null_without_reviewed(): void
    {
        $metadata = ChildrensTalkSpeakerMetadata::fromArray([
            'predicted' => ['preacher_name' => 'Someone'],
        ]);

        $this->assertNull($metadata->publicationSpeaker());
    }

    #[Test]
    public function childrens_talk_speaker_publication_speaker_returns_null_without_name(): void
    {
        $metadata = ChildrensTalkSpeakerMetadata::fromArray([
            'reviewed' => ['preacher_id' => 1],
        ]);

        $this->assertNull($metadata->publicationSpeaker());
    }

    // ── SectionOosAlignment ───────────────────────────────────────────────────

    #[Test]
    public function oos_alignment_creates_from_full_array(): void
    {
        $alignment = SectionOosAlignment::fromArray([
            'song_match_type' => 'fuzzy',
            'song_match_score' => 0.75,
            'matched_item_id' => 10,
            'matched_item_title' => 'How Great Thou Art',
            'base_confidence' => 0.8,
            'base_needs_manual_review' => false,
        ]);

        $this->assertSame('fuzzy', $alignment->raw['song_match_type']);
        $this->assertSame(0.75, $alignment->songMatchScore);
        $this->assertSame(10, $alignment->raw['matched_item_id']);
        $this->assertSame('How Great Thou Art', $alignment->matchedItemTitle);
        $this->assertSame(0.8, $alignment->baseConfidence);
        $this->assertFalse($alignment->baseNeedsManualReview);
    }

    #[Test]
    public function oos_alignment_returns_null_for_non_array(): void
    {
        $this->assertNull(SectionOosAlignment::fromArray(null));
        $this->assertNull(SectionOosAlignment::fromArray('string'));
    }

    #[Test]
    public function oos_alignment_to_array_strips_specific_raw_keys(): void
    {
        // toArray deliberately removes song_match_type, matched_item_id, expected_item_id from raw
        $alignment = SectionOosAlignment::fromArray([
            'song_match_type' => 'exact',
            'matched_item_id' => 5,
            'expected_item_id' => 6,
            'song_match_score' => 0.99,
        ]);

        $arr = $alignment->toArray();

        $this->assertArrayNotHasKey('song_match_type', $arr);
        $this->assertArrayNotHasKey('matched_item_id', $arr);
        $this->assertArrayNotHasKey('expected_item_id', $arr);
        $this->assertSame(0.99, $arr['song_match_score']);
    }
}
