<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\ServiceSection;
use App\Services\ChurchService\SectionItemAlignmentScorer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SectionItemAlignmentScorerTest extends TestCase
{
    private SectionItemAlignmentScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorer = new SectionItemAlignmentScorer;
    }

    #[Test]
    public function it_scores_media_interludes_with_bonus_for_cues(): void
    {
        $section = new ServiceSection;
        $item = new ChurchServiceItem;

        // No cue
        $result = $this->scorer->scorePair(
            $section,
            $item,
            ServiceSectionType::Song,
            treatAsMedia: true,
            hasMediaCue: false
        );

        $this->assertSame('media', $result['kind']);
        $this->assertEqualsWithDelta(SectionItemAlignmentScorer::MEDIA_INTERLUDE_SCORE, $result['score'], 0.001);

        // With cue
        $result = $this->scorer->scorePair(
            $section,
            $item,
            ServiceSectionType::Song,
            treatAsMedia: true,
            hasMediaCue: true
        );

        $this->assertSame('media', $result['kind']);
        $this->assertEqualsWithDelta(
            SectionItemAlignmentScorer::MEDIA_INTERLUDE_SCORE + SectionItemAlignmentScorer::MEDIA_CUE_BONUS,
            $result['score'],
            0.001
        );
    }

    #[Test]
    public function it_scores_exact_type_matches_with_content_bonus(): void
    {
        $section = new ServiceSection(['section_type' => ServiceSectionType::Sermon, 'title' => 'The Prodigal Son']);
        $item = new ChurchServiceItem(['title' => 'Sermon: Prodigal Son']);

        $result = $this->scorer->scorePair(
            $section,
            $item,
            ServiceSectionType::Sermon,
            treatAsMedia: false,
            hasMediaCue: false
        );

        $this->assertSame('match', $result['kind']);
        // "prodigal" and "son" should match. "sermon" is a token in item but also in section type (but section title doesn't have it).
        // Wait, tokens(The Prodigal Son) = [prodigal, son]
        // tokens(Sermon: Prodigal Son) = [sermon, prodigal, son]
        // overlap = 2 (prodigal, son)
        // bonus = 2 * 0.5 = 1.0
        $this->assertEqualsWithDelta(SectionItemAlignmentScorer::EXACT_MATCH_SCORE + 1.0, $result['score'], 0.001);
    }

    #[Test]
    public function it_scores_reclassifiable_matches_from_other_type(): void
    {
        $section = new ServiceSection(['section_type' => ServiceSectionType::Other, 'title' => 'Reading']);
        $item = new ChurchServiceItem(['title' => 'Scripture Reading']);

        $result = $this->scorer->scorePair(
            $section,
            $item,
            ServiceSectionType::BibleReading,
            treatAsMedia: false,
            hasMediaCue: false
        );

        $this->assertSame('reclassify', $result['kind']);
        // tokens(Reading) = [reading]
        // tokens(Scripture Reading) = [scripture, reading]
        // overlap = 1 (reading)
        // bonus = 0.5
        $this->assertEqualsWithDelta(SectionItemAlignmentScorer::RECLASSIFY_MATCH_SCORE + 0.5, $result['score'], 0.001);
    }

    #[Test]
    public function it_scores_structural_conflicts_when_types_disagree_and_not_reclassifiable(): void
    {
        $section = new ServiceSection(['section_type' => ServiceSectionType::Song]);
        $item = new ChurchServiceItem;

        $result = $this->scorer->scorePair(
            $section,
            $item,
            ServiceSectionType::Sermon,
            treatAsMedia: false,
            hasMediaCue: false
        );

        $this->assertSame('conflict', $result['kind']);
        $this->assertEqualsWithDelta(SectionItemAlignmentScorer::CONFLICT_SCORE, $result['score'], 0.001);
    }

    #[Test]
    public function it_caps_content_bonus_at_maximum(): void
    {
        $section = new ServiceSection([
            'section_type' => ServiceSectionType::Sermon,
            'title' => 'One Two Three Four Five Six Seven',
        ]);
        $item = new ChurchServiceItem(['title' => 'One Two Three Four Five Six Seven']);

        $result = $this->scorer->scorePair(
            $section,
            $item,
            ServiceSectionType::Sermon,
            treatAsMedia: false,
            hasMediaCue: false
        );

        // 7 tokens match. 7 * 0.5 = 3.5. Cap is 2.0.
        $this->assertEqualsWithDelta(SectionItemAlignmentScorer::EXACT_MATCH_SCORE + 2.0, $result['score'], 0.001);
    }

    #[Test]
    public function it_filters_stop_tokens_from_content_bonus(): void
    {
        $section = new ServiceSection([
            'section_type' => ServiceSectionType::Sermon,
            'title' => 'The Word of the Lord',
        ]);
        // "the", "of" are stop tokens. Only "word" and "lord" remain.
        $item = new ChurchServiceItem(['title' => 'The Word and the Lord']);
        // "and" is also a stop token.

        $result = $this->scorer->scorePair(
            $section,
            $item,
            ServiceSectionType::Sermon,
            treatAsMedia: false,
            hasMediaCue: false
        );

        // Overlap: word, lord (2 tokens)
        $this->assertEqualsWithDelta(SectionItemAlignmentScorer::EXACT_MATCH_SCORE + 1.0, $result['score'], 0.001);
    }

    #[Test]
    public function it_includes_transcript_in_content_bonus_haystack(): void
    {
        $section = new ServiceSection([
            'section_type' => ServiceSectionType::Sermon,
            'title' => 'Empty Title',
            'metadata' => ['transcript' => 'This is the actual content of the message.'],
        ]);
        $item = new ChurchServiceItem(['title' => 'Actual Content Message']);

        $result = $this->scorer->scorePair(
            $section,
            $item,
            ServiceSectionType::Sermon,
            treatAsMedia: false,
            hasMediaCue: false
        );

        // Tokens in item: actual, content, message
        // All 3 should match in transcript.
        // Bonus = 3 * 0.5 = 1.5
        $this->assertEqualsWithDelta(SectionItemAlignmentScorer::EXACT_MATCH_SCORE + 1.5, $result['score'], 0.001);
    }

    #[Test]
    public function it_provides_gap_scores(): void
    {
        $this->assertEqualsWithDelta(SectionItemAlignmentScorer::SECTION_GAP_SCORE, $this->scorer->sectionGapScore(), 0.001);
        $this->assertEqualsWithDelta(SectionItemAlignmentScorer::ITEM_GAP_SCORE, $this->scorer->itemGapScore(), 0.001);
    }
}
