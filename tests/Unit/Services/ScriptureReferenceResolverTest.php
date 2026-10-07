<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Scripture\ScriptureReferenceResolver;
use PHPUnit\Framework\TestCase;
use TechWilk\BibleVerseParser\BiblePassageParser;

class ScriptureReferenceResolverTest extends TestCase
{
    private ScriptureReferenceResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ScriptureReferenceResolver(new BiblePassageParser);
    }

    public function test_normalizes_standard_reference(): void
    {
        $result = $this->resolver->normalize('John 3:16');
        $this->assertSame('John 3:16', $result);
    }

    public function test_normalizes_abbreviated_book(): void
    {
        $result = $this->resolver->normalize('Jn 3:16');
        $this->assertNotNull($result);
        $this->assertStringContainsString('John', $result);
    }

    public function test_normalizes_range(): void
    {
        $result = $this->resolver->normalize('John 3:16-21');
        $this->assertNotNull($result);
        $this->assertStringContainsString('John', $result);
        $this->assertStringContainsString('3', $result);
    }

    public function test_normalizes_numbered_book(): void
    {
        $result = $this->resolver->normalize('1 John 3:16');
        $this->assertNotNull($result);
        $this->assertStringContainsString('John', $result);
    }

    public function test_normalizes_first_abbreviated_to_1(): void
    {
        $result = $this->resolver->normalize('1Jn 3:16-18');
        $this->assertNotNull($result);
    }

    public function test_returns_null_for_empty_string(): void
    {
        $this->assertNull($this->resolver->normalize(''));
        $this->assertNull($this->resolver->normalize('   '));
    }

    public function test_returns_null_for_gibberish(): void
    {
        $result = $this->resolver->normalize('xyzzy 99:99');
        $this->assertNull($result);
    }

    public function test_normalizes_verse_word_format(): void
    {
        $result = $this->resolver->normalize('John chapter 3 verse 16');
        $this->assertNotNull($result);
        $this->assertStringContainsString('John', $result);
    }

    public function test_normalize_all_preserves_every_passage(): void
    {
        $this->assertSame('John 3:16-18, John 4:1-2', $this->resolver->normalizeAll('John 3:16-18, 4:1-2'));
        $this->assertSame('1 Peter 2, 1 Peter 5', $this->resolver->normalizeAll('1 Peter 2, 5'));
    }

    public function test_normalize_all_returns_single_passage_unchanged(): void
    {
        $this->assertSame('John 3:16', $this->resolver->normalizeAll('John 3:16'));
        $this->assertSame('John 3:16', $this->resolver->normalizeAll('Jn 3:16'));
    }

    public function test_normalize_all_returns_null_for_unparseable_input(): void
    {
        $this->assertNull($this->resolver->normalizeAll(''));
        $this->assertNull($this->resolver->normalizeAll('The passage is John 3:16'));
        $this->assertNull($this->resolver->normalizeAll('xyzzy 99:99'));
    }

    public function test_references_agree_when_one_subdivides_the_other(): void
    {
        // The transcript often hears the planned passage as subranges.
        $this->assertTrue($this->resolver->referencesAgree('Luke 18:31-33, 35-43', 'Luke 18:31-43'));
        $this->assertTrue($this->resolver->referencesAgree('Luke 18:31-43', 'Luke 18:31-33, 35-43'));
        $this->assertTrue($this->resolver->referencesAgree('John 3:16', 'John 3:16-18'));
        $this->assertTrue($this->resolver->referencesAgree('John 3', 'John 3:16-18'));
        $this->assertTrue($this->resolver->referencesAgree('Jn 3:16', 'John 3:16'));
    }

    public function test_references_agree_when_split_at_different_points(): void
    {
        // Same continuous reading (John 3:1-20) subdivided at different points:
        // "1-10" and "6-20" cross, but the overall spans are identical, so this
        // is one reading, not a conflict.
        $this->assertTrue($this->resolver->referencesAgree('John 3:1-10, 11-20', 'John 3:1-5, 6-20'));
        $this->assertTrue($this->resolver->referencesAgree('John 3:1-5, 6-20', 'John 3:1-10, 11-20'));
    }

    public function test_references_disagree_on_genuinely_different_passages(): void
    {
        $this->assertFalse($this->resolver->referencesAgree('Luke 18:31-43', 'John 3:16'));
        $this->assertFalse($this->resolver->referencesAgree('Luke 18:1-8', 'Luke 18:31-43'));
        $this->assertFalse($this->resolver->referencesAgree('Luke 17', 'Luke 18'));
    }

    public function test_references_disagree_when_one_side_reads_beyond_the_other(): void
    {
        // A heard subrange outside the planned passage is worth a review flag.
        $this->assertFalse($this->resolver->referencesAgree('Luke 18:31-33, 35-43', 'Luke 18:31-33'));
    }

    public function test_references_disagree_on_a_crossing_partial_overlap(): void
    {
        // The passages share 18-20 but each reads beyond it (one starts earlier,
        // the other ends later): a genuine conflict, not a subrange subdivision.
        $this->assertFalse($this->resolver->referencesAgree('John 3:16-20', 'John 3:18-25'));
        $this->assertFalse($this->resolver->referencesAgree('John 3:18-25', 'John 3:16-20'));
    }

    public function test_references_never_agree_when_either_side_is_unparseable(): void
    {
        $this->assertFalse($this->resolver->referencesAgree('', 'John 3:16'));
        $this->assertFalse($this->resolver->referencesAgree('John 3:16', 'xyzzy 99:99'));
    }

    public function test_resolves_single_chapter_book_verse_references(): void
    {
        // "Book N" for a single-chapter book is verse N, not chapter N.
        $this->assertSame('Jude 1:3', $this->resolver->normalize('Jude 3'));
        $this->assertSame('Philemon 1:6', $this->resolver->normalize('Philemon 6'));
        $this->assertSame('Obadiah 1:2', $this->resolver->normalize('Obadiah 2'));
        $this->assertSame('3 John 1:4-8', $this->resolver->normalizeAll('3 John 4-8'));
        $this->assertSame('2 John 1:7', $this->resolver->normalizeAll('2 John 7'));
    }

    public function test_does_not_invent_chapters_for_multi_chapter_books(): void
    {
        // A genuinely out-of-range chapter must still be rejected.
        $this->assertNull($this->resolver->normalize('John 99'));
    }

    public function test_rewrites_single_chapter_parts_within_multi_part_references(): void
    {
        $this->assertSame('John 3:16, Jude 1:3', $this->resolver->normalizeAll('John 3:16; Jude 3'));
        $this->assertSame('2 Peter 1:3, Philemon 1:6', $this->resolver->normalizeAll('2 Peter 1:3 and Philemon 6'));
        $this->assertSame('Jude 1:3, Philemon 1:6', $this->resolver->normalizeAll('Jude 3 and Philemon 6'));
    }

    public function test_treats_single_chapter_verse_one_as_a_verse(): void
    {
        // "Jude 1" means verse 1, not the whole book (which is just "Jude").
        $this->assertSame('Jude 1:1', $this->resolver->normalize('Jude 1'));
        $this->assertSame('Philemon 1:1', $this->resolver->normalize('Philemon 1'));
        $this->assertSame('2 John 1:1', $this->resolver->normalize('2 John 1'));
    }

    public function test_supports_range_separators_in_single_chapter_references(): void
    {
        $this->assertSame('Jude 1:3-5', $this->resolver->normalize('Jude 3-5'));
        $this->assertSame('Jude 1:3-5', $this->resolver->normalize('Jude 3–5'));
        $this->assertSame('Philemon 1:3-5', $this->resolver->normalize('Philemon 3 to 5'));
    }

    public function test_same_span_accepts_formatting_variants_of_the_same_reference(): void
    {
        $this->assertTrue($this->resolver->referencesRenderSameSpan('Joshua 4:1-5:1', 'Joshua 4:1–5:1'));
        $this->assertTrue($this->resolver->referencesRenderSameSpan('John 3:16', 'John 3:16'));
    }

    public function test_same_span_rejects_a_display_form_that_lost_its_chapter_colon(): void
    {
        // api.bible renders JOS.4.1-JOS.5.1 as "Joshua 4:1-51" — a different
        // (here unparseable) reference, not a formatting variant.
        $this->assertFalse($this->resolver->referencesRenderSameSpan('Joshua 4:1-5:1', 'Joshua 4:1-51'));

        // The mangled form can also parse but nest inside the true span
        // (Matthew 1 has 25 verses, so "1:1-21" is valid); nesting must not
        // count as the same span — that is referencesAgree()'s job.
        $this->assertFalse($this->resolver->referencesRenderSameSpan('Matthew 1:1-2:1', 'Matthew 1:1-21'));
    }

    public function test_same_span_rejects_unparseable_sides(): void
    {
        $this->assertFalse($this->resolver->referencesRenderSameSpan('xyzzy 1:1', 'John 3:16'));
        $this->assertFalse($this->resolver->referencesRenderSameSpan('John 3:16', ''));
    }

    public function test_overlap_accepts_a_sermon_subrange_of_the_reading(): void
    {
        // A sermon usually expounds part of the passage read (the 2026-05-03
        // corpus run read 1 Timothy 3:14-4:16 and preached 4:7-10).
        $this->assertTrue($this->resolver->referencesOverlap('1 Timothy 3:14-4:16', '1 Timothy 4:7-10'));
        $this->assertTrue($this->resolver->referencesOverlap('Philippians 2:5-11', 'Philippians 2:5-11'));
    }

    public function test_overlap_accepts_a_crossing_overlap(): void
    {
        // Evidence ranking wants ANY shared verses; only referencesAgree()
        // rejects crossing spans.
        $this->assertTrue($this->resolver->referencesOverlap('Luke 18:31-43', 'Luke 18:35-19:10'));
    }

    public function test_overlap_rejects_disjoint_references(): void
    {
        $this->assertFalse($this->resolver->referencesOverlap('Psalm 72', 'Philippians 2:5-11'));
        $this->assertFalse($this->resolver->referencesOverlap('John 3:1-15', 'John 4:1-10'));
    }

    public function test_overlap_rejects_unparseable_sides(): void
    {
        $this->assertFalse($this->resolver->referencesOverlap('not a reference', 'John 3:16'));
        $this->assertFalse($this->resolver->referencesOverlap('', 'John 3:16'));
    }

    public function test_a_reading_contains_a_sermon_it_reads_in_full_beside_another_passage(): void
    {
        // Runs 964, 1073 and 1211: the reading carries one more passage than the sermon expounds.
        $this->assertTrue($this->resolver->referenceContains('Matthew 5:13-16; John 8:12-18', 'Matthew 5:13-16'));
        $this->assertTrue($this->resolver->referenceContains('Jonah 1:17, 2:1-10', 'Jonah 2:1-10'));
        $this->assertTrue($this->resolver->referenceContains('Genesis 8:13-22, 9:1-17', 'Genesis 8:22'));
        $this->assertTrue($this->resolver->referenceContains('Genesis 8:13-22, 9:1-17', 'Genesis 8:20-22; 9:8-17'));
    }

    public function test_containment_rejects_a_sermon_reading_past_the_passage_or_unparseable_sides(): void
    {
        $this->assertFalse($this->resolver->referenceContains('Genesis 8:1-19', 'Genesis 8:15-9:17'));
        $this->assertFalse($this->resolver->referenceContains('Genesis 8:20-22', 'Genesis 8:20-22; 9:8-17'));
        $this->assertFalse($this->resolver->referenceContains('not a reference', 'John 3:16'));
        $this->assertFalse($this->resolver->referenceContains('John 3', ''));
    }

    /**
     * Run 1250 (operator, 2026-10-07): Job 29, 30 and 31 were read, with a song and a prayer
     * between them, and the sermon expounded "Job 29-31". The readings together are the preached
     * passage, so all three are the sermon's, in their order.
     */
    public function test_several_readings_that_together_cover_the_passage_are_all_selected(): void
    {
        $membership = $this->resolver->sermonReadingMembership('Job 29-31', ['Job 29:1-25', 'Job 30:1-31', 'Job 31:1-40']);

        $this->assertSame(['selected' => [0, 1, 2], 'review' => false, 'could_be_cut' => [0, 1, 2]], $membership);
    }

    /** The same rule for a multipart reference: each reading is one of its parts. */
    public function test_readings_covering_a_multipart_reference_part_by_part_are_all_selected(): void
    {
        $membership = $this->resolver->sermonReadingMembership('Genesis 8:20-22; 9:8-17', ['Genesis 8:20-22', 'Genesis 9:8-17']);

        $this->assertSame([0, 1], $membership['selected']);
        $this->assertFalse($membership['review']);
    }

    /** An earlier unrelated reading (a call to worship) is neither selected nor a doubt. */
    public function test_an_unrelated_earlier_reading_stays_out_of_a_covering_set(): void
    {
        $membership = $this->resolver->sermonReadingMembership('Job 29-31', ['Psalm 95:1-7', 'Job 29', 'Job 30', 'Job 31']);

        $this->assertSame([1, 2, 3], $membership['selected']);
        $this->assertFalse($membership['review']);
    }

    /** One reading holding the passage is still the sermon's alone (run 949's John 19). */
    public function test_a_single_sufficient_reading_is_selected_alone(): void
    {
        $this->assertSame(['selected' => [1], 'review' => false, 'could_be_cut' => [1]],
            $this->resolver->sermonReadingMembership('John 19', ['Psalm 95:1-7', 'John 19:15-30']));
        $this->assertSame([1], $this->resolver->sermonReadingMembership('Job 29:1-10', ['Psalm 23', 'Job 29'])['selected']);
    }

    /**
     * Readings that do not add up to the passage stay a question: a chapter not read, two that
     * share verses, the same passage twice, a whole reading beside a part, a part reading past
     * the passage, or a reading or sermon without a reference.
     *
     * @param  list<string|null>  $readings
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('uncoveredOrCompetingReadings')]
    public function test_readings_that_do_not_cover_the_passage_exactly_go_to_review(?string $sermon, array $readings): void
    {
        $membership = $this->resolver->sermonReadingMembership($sermon, $readings);

        $this->assertSame([], $membership['selected']);
        $this->assertTrue($membership['review']);
    }

    /** @return array<string, array{0: string|null, 1: list<string|null>}> */
    public static function uncoveredOrCompetingReadings(): array
    {
        return [
            'a chapter not read' => ['Job 29-31', ['Job 29', 'Job 31']],
            'two parts sharing verses' => ['Job 29-31', ['Job 29:1-30:10', 'Job 30:1-31:40']],
            'the same part twice' => ['Job 29-31', ['Job 29', 'Job 30', 'Job 30:1-31', 'Job 31']],
            'a whole reading beside a part' => ['Job 29-31', ['Job 29-31', 'Job 30']],
            'a part reading past the passage' => ['Job 29-31', ['Job 29', 'Job 30', 'Job 31-32']],
            'a reading without a reference' => ['Job 29-31', ['Job 29', null, 'Job 30', 'Job 31']],
            'a sermon without a reference' => [null, ['Job 29', 'Job 30', 'Job 31']],
        ];
    }

    /** How a speaker introduces a passage: its book and chapter, however phrased. */
    public function test_spoken_words_name_a_passage_by_book_and_chapter(): void
    {
        $this->assertTrue($this->resolver->namesPassage('But as we begin, I want to start with Hebrews chapter 9,', 'Hebrews 9:11-14'));
        $this->assertTrue($this->resolver->namesPassage("There's one that stands out to me from Numbers 21.", 'Numbers 21:4-9'));
        $this->assertTrue($this->resolver->namesPassage('turn with me to First John 3', '1 John 3:1-10'));
        $this->assertTrue($this->resolver->namesPassage('Psalm 23, a psalm of David.', 'Psalm 23'));
        $this->assertTrue($this->resolver->namesPassage('we read Job 30 to 31', 'Job 29-31'));
    }

    /** Verses alone, a book alone, another chapter, or an unparseable reference name nothing. */
    public function test_verses_or_a_book_alone_name_no_passage(): void
    {
        $this->assertFalse($this->resolver->namesPassage('I do want to read verses 24 to 31 to you.', 'Job 30:24-31'));
        $this->assertFalse($this->resolver->namesPassage('the letter to the Hebrews is about Christ', 'Hebrews 9:11-14'));
        $this->assertFalse($this->resolver->namesPassage('Hebrews chapter 10', 'Hebrews 9:11-14'));
        $this->assertFalse($this->resolver->namesPassage('the numbers 21 and 22 were drawn', 'Numbers 21:4-9'), 'not a book name in lower case without context');
        $this->assertFalse($this->resolver->namesPassage('Hebrews 9', 'not a reference'));
    }
}
