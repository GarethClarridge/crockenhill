<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\ChurchServiceImportMetadata;
use App\Data\ChurchServiceImportMetadataCast;
use App\Data\ChurchServiceManualReviewMetadata;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceMetadataDataTest extends TestCase
{
    // ── ChurchServiceManualReviewMetadata ─────────────────────────────────────

    #[Test]
    public function manual_review_creates_from_full_array(): void
    {
        $metadata = ChurchServiceManualReviewMetadata::fromArray([
            'reviewed_at' => '2024-03-10T09:00:00Z',
            'reviewed_by_user_id' => 3,
            'reopened_at' => '2024-03-11T10:00:00Z',
            'reopened_by_source' => 'openlp',
        ]);

        $this->assertSame('2024-03-10T09:00:00Z', $metadata->reviewedAt);
        $this->assertSame(3, $metadata->reviewedByUserId);
        $this->assertSame('2024-03-11T10:00:00Z', $metadata->reopenedAt);
        $this->assertSame('openlp', $metadata->reopenedBySource);
    }

    #[Test]
    public function manual_review_creates_from_empty_array(): void
    {
        $metadata = ChurchServiceManualReviewMetadata::fromArray([]);

        $this->assertNull($metadata->reviewedAt);
        $this->assertNull($metadata->reviewedByUserId);
    }

    #[Test]
    public function manual_review_returns_null_for_non_array(): void
    {
        $this->assertNull(ChurchServiceManualReviewMetadata::fromArray(null));
        $this->assertNull(ChurchServiceManualReviewMetadata::fromArray('string'));
    }

    #[Test]
    public function manual_review_round_trips_via_to_array(): void
    {
        $metadata = ChurchServiceManualReviewMetadata::fromArray([
            'reviewed_at' => '2024-01-01T10:00:00Z',
            'reviewed_by_user_id' => 7,
        ]);

        $arr = $metadata->toArray();
        $this->assertSame('2024-01-01T10:00:00Z', $arr['reviewed_at']);
        $this->assertSame(7, $arr['reviewed_by_user_id']);
    }

    #[Test]
    public function manual_review_throws_on_array_write(): void
    {
        $metadata = ChurchServiceManualReviewMetadata::fromArray([]);

        $this->expectException(LogicException::class);
        $metadata['key'] = 'value'; // @phpstan-ignore-line
    }

    // ── ChurchServiceImportMetadata ───────────────────────────────────────────

    #[Test]
    public function import_metadata_creates_from_full_array(): void
    {
        $metadata = ChurchServiceImportMetadata::fromArray([
            'confidence_score' => 0.92,
            'parse_method' => 'openlp',
            'warnings' => ['Missing series', 'Short transcript'],
            'review_triggers' => ['low_confidence'],
            'canonical_conflict_history' => [['event' => 'conflict_detected']],
            'manual_review' => ['reviewed_at' => '2024-01-01T12:00:00Z'],
            'canonical_conflict' => ['detected_at' => '2024-01-02T08:00:00Z'],
        ]);

        $this->assertSame(0.92, $metadata->confidenceScore);
        $this->assertSame('openlp', $metadata->parseMethod);
        $this->assertSame(['Missing series', 'Short transcript'], $metadata->warnings);
        $this->assertSame(['low_confidence'], $metadata->reviewTriggers);
        $this->assertCount(1, $metadata->canonicalConflictHistory);
        $this->assertInstanceOf(ChurchServiceManualReviewMetadata::class, $metadata->manualReview);
        $this->assertArrayNotHasKey('canonical_conflict', $metadata->toArray());
    }

    #[Test]
    public function import_metadata_creates_from_empty_input(): void
    {
        $metadata = ChurchServiceImportMetadata::fromArray([]);

        $this->assertNull($metadata->confidenceScore);
        $this->assertNull($metadata->parseMethod);
        $this->assertSame([], $metadata->warnings);
        $this->assertNull($metadata->manualReview);
    }

    #[Test]
    public function import_metadata_always_returns_self_even_for_null(): void
    {
        // Unlike nullable DTOs, fromArray always returns self
        $metadata = ChurchServiceImportMetadata::fromArray(null);

        $this->assertInstanceOf(ChurchServiceImportMetadata::class, $metadata);
    }

    #[Test]
    public function import_metadata_round_trips_via_to_array(): void
    {
        $metadata = ChurchServiceImportMetadata::fromArray([
            'confidence_score' => 0.75,
            'parse_method' => 'oos_email',
            'warnings' => ['Unmatched song'],
        ]);

        $arr = $metadata->toArray();
        $this->assertSame(0.75, $arr['confidence_score']);
        $this->assertSame(['Unmatched song'], $arr['warnings']);
    }

    // ── ChurchServiceImportMetadataCast ───────────────────────────────────────

    #[Test]
    public function cast_get_deserialises_json_to_import_metadata(): void
    {
        $cast = new ChurchServiceImportMetadataCast;
        $model = $this->createStub(Model::class);

        $json = json_encode(['confidence_score' => 0.8, 'parse_method' => 'openlp']);
        $result = $cast->get($model, 'import_metadata', $json, []);

        $this->assertInstanceOf(ChurchServiceImportMetadata::class, $result);
        $this->assertSame(0.8, $result->confidenceScore);
    }

    #[Test]
    public function cast_get_returns_empty_metadata_for_null(): void
    {
        $cast = new ChurchServiceImportMetadataCast;
        $model = $this->createStub(Model::class);

        $result = $cast->get($model, 'import_metadata', null, []);
        $this->assertInstanceOf(ChurchServiceImportMetadata::class, $result);
    }

    #[Test]
    public function cast_set_serialises_to_json(): void
    {
        $cast = new ChurchServiceImportMetadataCast;
        $model = $this->createStub(Model::class);

        $metadata = ChurchServiceImportMetadata::fromArray(['confidence_score' => 0.9]);
        $result = $cast->set($model, 'import_metadata', $metadata, []);

        $this->assertIsString($result);
        $decoded = json_decode($result, true);
        $this->assertSame(0.9, $decoded['confidence_score']);
    }

    #[Test]
    public function cast_set_returns_null_for_null(): void
    {
        $cast = new ChurchServiceImportMetadataCast;
        $model = $this->createStub(Model::class);

        $this->assertNull($cast->set($model, 'import_metadata', null, []));
    }

    #[Test]
    public function cast_round_trips_import_metadata(): void
    {
        $cast = new ChurchServiceImportMetadataCast;
        $model = $this->createStub(Model::class);

        $original = ChurchServiceImportMetadata::fromArray([
            'confidence_score' => 0.95,
            'parse_method' => 'openlp',
            'warnings' => ['note1'],
        ]);

        $json = $cast->set($model, 'import_metadata', $original, []);
        $restored = $cast->get($model, 'import_metadata', $json, []);

        $this->assertSame(0.95, $restored->confidenceScore);
        $this->assertSame('openlp', $restored->parseMethod);
        $this->assertSame(['note1'], $restored->warnings);
    }
}
