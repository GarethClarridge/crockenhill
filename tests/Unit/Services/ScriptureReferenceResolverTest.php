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
}
