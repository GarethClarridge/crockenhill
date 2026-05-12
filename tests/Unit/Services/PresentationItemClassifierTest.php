<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Services\PresentationItemClassifier;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PresentationItemClassifierTest extends TestCase
{
    private PresentationItemClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new PresentationItemClassifier;
    }

    // ── Tier 1: explicit section_type column ───────────────────────────────

    #[Test]
    public function it_returns_explicit_decision_when_section_type_column_is_set(): void
    {
        $item = $this->makeItem(title: 'Anything', sectionType: ServiceSectionType::CHILDRENS_TALK);
        $result = $this->classifyOne($item);

        $this->assertSame(ServiceSectionType::CHILDRENS_TALK, $result['resolved_type']);
        $this->assertSame('explicit', $result['evidence']);
        $this->assertFalse($result['requires_review']);
        $this->assertNull($result['review_flag']);
    }

    #[Test]
    public function it_falls_through_to_tier_two_when_legacy_metadata_section_type_exists(): void
    {
        $item = $this->makeItem(title: 'Children', metadata: ['section_type' => 'not_a_real_type']);
        $result = $this->classifyOne($item);

        $this->assertSame(ServiceSectionType::CHILDRENS_TALK, $result['resolved_type']);
        $this->assertSame('strong', $result['evidence']);
    }

    // ── Tier 2a: strong – children keyword ──────────────────────────────────

    #[Test]
    public function it_classifies_childrens_talk_by_title_keyword(): void
    {
        $item = $this->makeItem(title: "Children's Talk");
        $result = $this->classifyOne($item);

        $this->assertSame(ServiceSectionType::CHILDRENS_TALK, $result['resolved_type']);
        $this->assertSame('strong', $result['evidence']);
        $this->assertTrue($result['requires_review']);
        $this->assertSame('inferred_childrens_talk', $result['review_flag']);
        $this->assertSame('presentation_title_children_keyword', $result['reason']);
    }

    #[Test]
    public function it_classifies_childrens_talk_when_title_is_just_children(): void
    {
        $item = $this->makeItem(title: 'Children');
        $result = $this->classifyOne($item);

        $this->assertSame(ServiceSectionType::CHILDRENS_TALK, $result['resolved_type']);
        $this->assertSame('strong', $result['evidence']);
    }

    // ── Tier 2b: strong – notices keyword ───────────────────────────────────

    #[Test]
    public function it_classifies_notices_by_title_keyword(): void
    {
        $item = $this->makeItem(title: 'Church Notices');
        $result = $this->classifyOne($item);

        $this->assertSame(ServiceSectionType::NOTICES, $result['resolved_type']);
        $this->assertSame('strong', $result['evidence']);
        $this->assertFalse($result['requires_review']);
        $this->assertNull($result['review_flag']);
        $this->assertSame('presentation_title_notices_keyword', $result['reason']);
    }

    #[Test]
    public function it_classifies_announcements_as_notices(): void
    {
        $item = $this->makeItem(title: 'Announcements');
        $result = $this->classifyOne($item);

        $this->assertSame(ServiceSectionType::NOTICES, $result['resolved_type']);
        $this->assertSame('strong', $result['evidence']);
    }

    // ── Tier 3: weak – position only ────────────────────────────────────────

    #[Test]
    public function it_suspects_notices_for_pre_song_presentation(): void
    {
        $song = $this->makeItem(type: 'songs', title: 'First Song', id: 10, position: 2);
        $presentation = $this->makeItem(title: 'Something', id: 20, position: 1);
        $result = $this->classifyOne($presentation, extraItems: [$song]);

        $this->assertSame(ServiceSectionType::OTHER, $result['resolved_type']);
        $this->assertSame(ServiceSectionType::NOTICES, $result['suspected_type']);
        $this->assertSame('weak', $result['evidence']);
        $this->assertFalse($result['requires_review']);
        $this->assertSame('pre_first_song_presentation', $result['reason']);
    }

    #[Test]
    public function it_suspects_childrens_talk_for_post_song_presentation(): void
    {
        $song = $this->makeItem(type: 'songs', title: 'First Song', id: 10, position: 2);
        $presentation = $this->makeItem(title: 'Mid-service Slot', id: 20, position: 5);
        $result = $this->classifyOne($presentation, extraItems: [$song]);

        $this->assertSame(ServiceSectionType::OTHER, $result['resolved_type']);
        $this->assertSame(ServiceSectionType::CHILDRENS_TALK, $result['suspected_type']);
        $this->assertSame('weak', $result['evidence']);
        $this->assertSame('post_first_song_presentation', $result['reason']);
    }

    #[Test]
    public function it_suspects_notices_when_no_song_items_exist(): void
    {
        $item = $this->makeItem(title: 'Something', position: 1);
        $result = $this->classifyOne($item);

        // With no song items, firstSongPosition = PHP_INT_MAX, so position <= INT_MAX → pre-song
        $this->assertSame('pre_first_song_presentation', $result['reason']);
        $this->assertSame(ServiceSectionType::NOTICES, $result['suspected_type']);
    }

    // ── classify: aggregate behavior ────────────────────────────────────────

    #[Test]
    public function it_skips_non_presentation_items_during_classify(): void
    {
        $song = $this->makeItem(type: 'songs', title: 'A Song', id: 1, position: 1);
        $bible = $this->makeItem(type: 'bibles', title: 'Romans 8', id: 2, position: 2);
        $items = new EloquentCollection([$song, $bible]);

        $result = $this->classifier->classify($items);

        $this->assertSame([], $result['decisions']);
        $this->assertSame(0, $result['childrens_talk_count']);
    }

    #[Test]
    public function it_counts_multiple_childrens_talk_items(): void
    {
        $song = $this->makeItem(type: 'songs', title: 'A Song', id: 1, position: 2);
        $first = $this->makeItem(title: "Children's Talk", id: 2, position: 3);
        $second = $this->makeItem(title: 'Children', id: 3, position: 5);
        $items = new EloquentCollection([$song, $first, $second]);

        $result = $this->classifier->classify($items);

        $this->assertSame(2, $result['childrens_talk_count']);
        $this->assertArrayHasKey($first->id, $result['decisions']);
        $this->assertArrayHasKey($second->id, $result['decisions']);
    }

    #[Test]
    public function it_uses_first_song_position_correctly_for_pre_post_classification(): void
    {
        $preSong = $this->makeItem(title: 'Welcome Slot', id: 10, position: 1);
        $song = $this->makeItem(type: 'songs', title: 'First Song', id: 20, position: 2);
        $postSong = $this->makeItem(title: 'Mid-service Slot', id: 30, position: 4);
        $items = new EloquentCollection([$preSong, $song, $postSong]);

        $result = $this->classifier->classify($items);

        $this->assertSame('pre_first_song_presentation', $result['decisions'][$preSong->id]['reason']);
        $this->assertSame('post_first_song_presentation', $result['decisions'][$postSong->id]['reason']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Classify a single presentation item, optionally alongside extra items (e.g. a song
     * to set the first-song-position reference). Returns the decision for the target item.
     *
     * @param  ChurchServiceItem[]  $extraItems
     * @return array{resolved_type: ServiceSectionType, suspected_type: ServiceSectionType|null, evidence: string, requires_review: bool, review_flag: string|null, reason: string}
     */
    private function classifyOne(ChurchServiceItem $item, array $extraItems = []): array
    {
        $items = new EloquentCollection(array_merge([$item], $extraItems));
        $result = $this->classifier->classify($items);

        return $result['decisions'][$item->id];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function makeItem(
        string $type = 'presentations',
        string $title = 'Test Item',
        int $id = 1,
        int $position = 1,
        array $metadata = [],
        ?ServiceSectionType $sectionType = null,
    ): ChurchServiceItem {
        $item = new ChurchServiceItem;
        $item->id = $id;
        $item->type = $type;
        $item->title = $title;
        $item->position = $position;
        $item->metadata = $metadata;
        $item->section_type = $sectionType;

        return $item;
    }
}
