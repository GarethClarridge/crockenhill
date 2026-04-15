<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\PendingStructureMergeMetadata;
use App\Data\StructureMergeResolution;
use App\Data\StructureMergeResult;
use App\Enums\ChurchServiceItemSource;
use App\Models\ChurchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StructureMergeDataTest extends TestCase
{
    use RefreshDatabase;

    // ── PendingStructureMergeMetadata ─────────────────────────────────────────

    #[Test]
    public function pending_merge_creates_from_full_array(): void
    {
        $metadata = PendingStructureMergeMetadata::fromArray([
            'incoming_source' => 'openlp',
            'created_at' => '2024-03-10T09:00:00Z',
            'confidence' => ['current' => 'high', 'incoming' => 'medium'],
            'conflicts' => [['type' => 'order_mismatch']],
            'proposed_items' => [['position' => 1, 'title' => 'Song A']],
            'classification' => [
                'auto_merge' => [1, 2],
                'review_required' => [3],
                'unmatched_incoming' => [],
            ],
        ]);

        $this->assertSame('openlp', $metadata->incomingSource);
        $this->assertSame('2024-03-10T09:00:00Z', $metadata->createdAt);
        $this->assertSame('high', $metadata->confidence['current']);
        $this->assertSame('medium', $metadata->confidence['incoming']);
        $this->assertCount(1, $metadata->conflicts);
        $this->assertCount(1, $metadata->proposedItems);
        $this->assertSame([1, 2], $metadata->classification['auto_merge']);
        $this->assertSame([3], $metadata->classification['review_required']);
    }

    #[Test]
    public function pending_merge_returns_null_for_non_array(): void
    {
        $this->assertNull(PendingStructureMergeMetadata::fromArray(null));
        $this->assertNull(PendingStructureMergeMetadata::fromArray('string'));
    }

    #[Test]
    public function pending_merge_defaults_confidence_to_unknown_when_missing(): void
    {
        $metadata = PendingStructureMergeMetadata::fromArray([]);

        $this->assertSame('unknown', $metadata->confidence['current']);
        $this->assertSame('unknown', $metadata->confidence['incoming']);
    }

    #[Test]
    public function pending_merge_filters_non_int_classification_ids(): void
    {
        $metadata = PendingStructureMergeMetadata::fromArray([
            'classification' => [
                'auto_merge' => [1, 'bad', 3],
                'review_required' => ['x', 2],
                'unmatched_incoming' => [],
            ],
        ]);

        $this->assertSame([1, 3], $metadata->classification['auto_merge']);
        $this->assertSame([2], $metadata->classification['review_required']);
    }

    #[Test]
    public function pending_merge_round_trips_via_to_array(): void
    {
        $metadata = PendingStructureMergeMetadata::fromArray([
            'incoming_source' => 'oos_email',
            'confidence' => ['current' => 'high', 'incoming' => 'low'],
            'conflicts' => [['type' => 'x']],
        ]);

        $arr = $metadata->toArray();
        $this->assertSame('oos_email', $arr['incoming_source']);
        $this->assertSame(['current' => 'high', 'incoming' => 'low'], $arr['confidence']);
        $this->assertCount(1, $arr['conflicts']);
    }

    // ── StructureMergeResolution ──────────────────────────────────────────────

    #[Test]
    public function structure_merge_resolution_stores_all_properties(): void
    {
        $churchService = ChurchService::factory()->create();

        $resolution = new StructureMergeResolution(
            churchService: $churchService,
            resolution: 'auto_merged',
            applied: true,
            reason: 'All items matched',
        );

        $this->assertSame($churchService->id, $resolution->churchService->id);
        $this->assertSame('auto_merged', $resolution->resolution);
        $this->assertTrue($resolution->applied);
        $this->assertSame('All items matched', $resolution->reason);
    }

    #[Test]
    public function structure_merge_resolution_reason_defaults_to_empty_string(): void
    {
        $churchService = ChurchService::factory()->create();

        $resolution = new StructureMergeResolution(
            churchService: $churchService,
            resolution: 'staged',
            applied: false,
        );

        $this->assertSame('', $resolution->reason);
    }

    // ── StructureMergeResult ──────────────────────────────────────────────────

    #[Test]
    public function structure_merge_result_stores_all_properties(): void
    {
        $churchService = ChurchService::factory()->create();

        $result = new StructureMergeResult(
            churchService: $churchService,
            incomingSource: ChurchServiceItemSource::OpenLp,
            wasMerged: true,
            wasStaged: false,
            syncResult: ['conflicts' => []],
            stagedConflicts: [],
            reason: 'Merged successfully',
        );

        $this->assertSame($churchService->id, $result->churchService->id);
        $this->assertSame(ChurchServiceItemSource::OpenLp, $result->incomingSource);
        $this->assertTrue($result->wasMerged);
        $this->assertFalse($result->wasStaged);
        $this->assertFalse($result->hasConflicts());
    }

    #[Test]
    public function structure_merge_result_has_conflicts_when_staged_conflicts_present(): void
    {
        $churchService = ChurchService::factory()->create();

        $result = new StructureMergeResult(
            churchService: $churchService,
            incomingSource: ChurchServiceItemSource::Email,
            wasMerged: false,
            wasStaged: true,
            stagedConflicts: [['type' => 'order_mismatch']],
        );

        $this->assertTrue($result->hasConflicts());
    }
}
