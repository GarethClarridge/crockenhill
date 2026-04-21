<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ChurchServiceItemSource;
use App\Services\StructureMergePolicy;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for StructureMergePolicy::classifyIncomingItems — identity-first matching (PR 5).
 *
 * These tests verify that reordered songs are matched by title rather than position,
 * eliminating false review triggers for routine service order changes.
 */
class StructureMergePolicyTest extends TestCase
{
    private StructureMergePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = app(StructureMergePolicy::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Song reordering — should auto-merge, not stage review
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function test_reordered_high_confidence_songs_auto_merge_not_review(): void
    {
        // Livestream projected: Amazing Grace (pos 1), Sermon (pos 2), How Great Thou Art (pos 3)
        // OpenLP arrives:       How Great Thou Art (pos 1), Sermon (pos 2), Amazing Grace (pos 3)
        // Without identity-first matching, positions 1 and 3 would each flag as review_required.
        $existing = [
            $this->songSnapshot('Amazing Grace', 1, 'high'),
            $this->customSnapshot('Sermon', 2, 'sermon', 'high'),
            $this->songSnapshot('How Great Thou Art', 3, 'high'),
        ];

        $incoming = [
            $this->songIncoming('How Great Thou Art', 1, 'how great thou art@'),
            $this->customIncoming('Sermon', 2, 'sermon'),
            $this->songIncoming('Amazing Grace', 3, 'amazing grace@'),
        ];

        $result = $this->policy->classifyIncomingItems($existing, $incoming, ChurchServiceItemSource::OpenLp);

        $this->assertSame([0, 1, 2], $result['auto_merge'], 'All three items should auto-merge after identity matching resolves the reorder');
        $this->assertEmpty($result['review_required']);
        $this->assertEmpty($result['unmatched_incoming']);
    }

    #[Test]
    public function test_two_songs_swapped_both_auto_merge(): void
    {
        $existing = [
            $this->songSnapshot('Come Thou Fount', 1, 'high'),
            $this->songSnapshot('Be Thou My Vision', 2, 'high'),
        ];

        $incoming = [
            $this->songIncoming('Be Thou My Vision', 1, 'be thou my vision@'),
            $this->songIncoming('Come Thou Fount', 2, 'come thou fount@'),
        ];

        $result = $this->policy->classifyIncomingItems($existing, $incoming, ChurchServiceItemSource::OpenLp);

        $this->assertSame([0, 1], $result['auto_merge']);
        $this->assertEmpty($result['review_required']);
    }

    #[Test]
    public function test_each_existing_snapshot_claimed_only_once(): void
    {
        // Two incoming songs with the same title should not both match the same existing snapshot.
        $existing = [
            $this->songSnapshot('Amazing Grace', 1, 'high'),
            $this->songSnapshot('Amazing Grace', 2, 'high'),
        ];

        $incoming = [
            $this->songIncoming('Amazing Grace', 1, 'amazing grace@'),
            $this->songIncoming('Amazing Grace', 2, 'amazing grace@'),
        ];

        $result = $this->policy->classifyIncomingItems($existing, $incoming, ChurchServiceItemSource::OpenLp);

        // Both matched (one each), none unmatched
        $this->assertCount(2, array_merge($result['auto_merge'], $result['review_required']));
        $this->assertEmpty($result['unmatched_incoming']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // openlp_search_title matching
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function test_song_with_no_title_matched_by_openlp_search_title_auto_merges(): void
    {
        // Livestream may produce an item with blank title but a search title set later by OpenLP.
        $existing = [
            array_merge($this->songSnapshot('', 1, 'high'), ['openlp_search_title' => 'amazing grace@']),
        ];

        $incoming = [
            $this->songIncoming('Amazing Grace', 1, 'amazing grace@'),
        ];

        $result = $this->policy->classifyIncomingItems($existing, $incoming, ChurchServiceItemSource::OpenLp);

        // isEnrichmentOnly fires (blank existing title) → auto_merge
        $this->assertSame([0], $result['auto_merge']);
        $this->assertEmpty($result['review_required']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Genuine conflict (different song, same position) → still review_required
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function test_genuinely_different_song_at_same_position_still_review_required(): void
    {
        $existing = [
            $this->songSnapshot('Amazing Grace', 1, 'high'),
        ];

        $incoming = [
            $this->songIncoming('How Great Thou Art', 1, 'how great thou art@'),
        ];

        $result = $this->policy->classifyIncomingItems($existing, $incoming, ChurchServiceItemSource::OpenLp);

        $this->assertEmpty($result['auto_merge']);
        $this->assertSame([0], $result['review_required']);
        $this->assertEmpty($result['unmatched_incoming']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Non-song section with same section_type twice — only changed one flagged
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function test_two_prayers_only_changed_one_is_review_required(): void
    {
        $existing = [
            $this->customSnapshot('Opening Prayer', 1, 'prayer', 'high'),
            $this->customSnapshot('Closing Prayer', 2, 'prayer', 'high'),
        ];

        $incoming = [
            $this->customIncoming('Opening Prayer', 1, 'prayer'),  // same title → identity match → auto_merge
            $this->customIncoming('Intercession', 2, 'prayer'),    // different title → position match → isAutoMergeSafe fails
        ];

        $result = $this->policy->classifyIncomingItems($existing, $incoming, ChurchServiceItemSource::OpenLp);

        $this->assertSame([0], $result['auto_merge'], 'Opening Prayer unchanged should auto-merge');
        $this->assertSame([1], $result['review_required'], 'Changed prayer should require review');
        $this->assertEmpty($result['unmatched_incoming']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function songSnapshot(string $title, int $position, string $confidence, ?string $openlpSearchTitle = null): array
    {
        return [
            'id' => $position,
            'position' => $position,
            'type' => 'songs',
            'section_type' => 'song',
            'source' => ChurchServiceItemSource::Livestream->value,
            'title' => $title,
            'source_title' => null,
            'openlp_search_title' => $openlpSearchTitle,
            'song_id' => null,
            'metadata' => [
                'livestream_projection' => [
                    'processing_id' => 'test',
                    'service_section_id' => $position,
                    'source_segment_ids' => [],
                    'confidence_level' => $confidence,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customSnapshot(string $title, int $position, string $sectionType, string $confidence): array
    {
        return [
            'id' => $position,
            'position' => $position,
            'type' => 'custom',
            'section_type' => $sectionType,
            'source' => ChurchServiceItemSource::Livestream->value,
            'title' => $title,
            'source_title' => null,
            'openlp_search_title' => null,
            'song_id' => null,
            'metadata' => [
                'livestream_projection' => [
                    'processing_id' => 'test',
                    'service_section_id' => $position,
                    'source_segment_ids' => [],
                    'confidence_level' => $confidence,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function songIncoming(string $title, int $position, string $openlpSearchTitle): array
    {
        return [
            'position' => $position,
            'type' => 'songs',
            'section_type' => 'song',
            'title' => $title,
            'source_title' => null,
            'openlp_search_title' => $openlpSearchTitle,
            'song_id' => null,
            'metadata' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customIncoming(string $title, int $position, string $sectionType): array
    {
        return [
            'position' => $position,
            'type' => 'custom',
            'section_type' => $sectionType,
            'title' => $title,
            'source_title' => null,
            'openlp_search_title' => null,
            'song_id' => null,
            'metadata' => null,
        ];
    }
}
