<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\SongTitleMatch;
use App\Services\Song\SongTitleResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SongTitleResolverTest extends TestCase
{
    /**
     * A miniature catalog mirroring the shapes found in the real library: OpenLP canonical
     * keys with trailing Praise! numbers, decorated alternate titles, punctuated first-line
     * keys, and the two-hymns-one-title ambiguity ("Abide With Me").
     */
    private function resolver(array $options = []): SongTitleResolver
    {
        return SongTitleResolver::fromRows([
            ['id' => 1, 'canonical_key' => 'how sweet the name of jesus sounds 299', 'title' => 'How Sweet The Name Of Jesus Sounds #299', 'praise_number' => '299'],
            ['id' => 2, 'canonical_key' => 'crown him with many crowns 046a', 'title' => 'Crown Him With Many Crowns #046A', 'praise_number' => '046A'],
            ['id' => 3, 'canonical_key' => 'all heaven declares 477', 'title' => 'All Heaven Declares #477', 'praise_number' => '477'],
            ['id' => 4, 'canonical_key' => 'what love could remember', 'title' => 'What love could remember', 'alternate_title' => 'His mercy is more'],
            ['id' => 5, 'canonical_key' => 'when i fear my faith will fail', 'title' => 'When I Fear My Faith Will Fail', 'alternate_title' => 'He Will Hold Me Fast'],
            ['id' => 6, 'canonical_key' => 'all creatures of our god and king 203', 'title' => 'All Creatures Of Our God And King #203', 'praise_number' => '203', 'alternate_title' => '#203 All Creatures Of Our God And King', 'first_line_key' => 'all creatures of our god and king,'],
            ['id' => 7, 'canonical_key' => 'hear the songs of angels rise', 'title' => 'Hear the songs of angels rise', 'alternate_title' => 'Comfort and joy'],
            ['id' => 8, 'canonical_key' => 'speak o lord', 'title' => 'Speak O Lord'],
            ['id' => 9, 'canonical_key' => 'when i was lost', 'title' => 'When I Was Lost', 'alternate_title' => 'There Is A New Song', 'first_line_key' => 'when i was lost,'],
            ['id' => 10, 'canonical_key' => 'there is a redeemer 344', 'title' => 'There Is A Redeemer #344', 'praise_number' => '344'],
            ['id' => 11, 'canonical_key' => 'see him in jerusalem', 'title' => 'See Him In Jerusalem'],
            ['id' => 12, 'canonical_key' => 'to see the king of heaven fall 1190', 'title' => 'To see the king of heaven fall #1190', 'praise_number' => '1190'],
            ['id' => 13, 'canonical_key' => 'praise to the lord the almighty 74', 'title' => 'Praise To The Lord The Almighty #74', 'praise_number' => '74'],
            ['id' => 14, 'canonical_key' => 'abide with me 45', 'title' => 'Abide With Me #45', 'praise_number' => '45'],
            ['id' => 15, 'canonical_key' => 'abide with me 900', 'title' => 'Abide With Me #900', 'praise_number' => '900'],
            ['id' => 16, 'canonical_key' => 'great is thy faithfulness 10', 'title' => 'Great Is Thy Faithfulness #10', 'praise_number' => '10'],
            ['id' => 17, 'canonical_key' => 'come let us join our cheerful songs 300', 'title' => 'Come, Let Us Join Our Cheerful Songs #300', 'praise_number' => '300'],
            ['id' => 18, 'canonical_key' => 'o come all ye faithful 201', 'title' => 'O Come All Ye Faithful #201', 'praise_number' => '201'],
            ['id' => 19, 'canonical_key' => 'from the breaking of the dawn', 'title' => 'From The Breaking Of The Dawn', 'alternate_title' => 'Every Promise', 'first_line_key' => 'from the breaking of the dawn'],
            ['id' => 20, 'canonical_key' => 'in christ alone 1072', 'title' => 'In Christ Alone #1072', 'praise_number' => '1072', 'first_line_key' => 'in christ alone my hope is found'],
            ['id' => 21, 'canonical_key' => 'all creatures of our god and king adapted', 'title' => 'All creatures of our God and King (adapted)', 'alternate_title' => '203 (adapted) All creatures of our God and King', 'first_line_key' => 'all creatures of our god and king'],
            ['id' => 22, 'canonical_key' => 'behold the lamb 1118', 'title' => 'Behold The Lamb #1118', 'praise_number' => '1118'],
            ['id' => 23, 'canonical_key' => 'how deep the fathers love for us 426', 'title' => "How Deep The Father's Love For Us #426", 'praise_number' => '426'],
            ['id' => 24, 'canonical_key' => 'all praise to him', 'title' => 'All Praise To Him'],
            ['id' => 25, 'canonical_key' => 'my heart is full 494', 'title' => 'My Heart Is Full #494', 'praise_number' => '494'],
            ['id' => 26, 'canonical_key' => 'yes finished the messiah dies 452', 'title' => 'Yes Finished! The Messiah Dies #452', 'praise_number' => '452'],
            // A catalogued title that opens with a role word, so the label rung has something
            // real it could damage.
            ['id' => 27, 'canonical_key' => 'song of the redeemed', 'title' => 'Song Of The Redeemed'],
            ['id' => 28, 'canonical_key' => 'carol of the bells', 'title' => 'Carol Of The Bells'],
        ], $options);
    }

    #[Test]
    #[DataProvider('deterministicResolutionProvider')]
    public function it_resolves_titles_through_the_deterministic_rungs(string $searchTitle, int $expectedSongId, string $expectedMatchType): void
    {
        $match = $this->resolver(['fuzzy_enabled' => false])->resolve($searchTitle);

        $this->assertNotNull($match, "Expected '{$searchTitle}' to resolve deterministically.");
        $this->assertSame($expectedSongId, $match->songId);
        $this->assertSame($expectedMatchType, $match->matchType);
        $this->assertSame(1.0, $match->confidence);
    }

    public static function deterministicResolutionProvider(): array
    {
        return [
            'exact canonical key' => ['how sweet the name of jesus sounds 299', 1, SongTitleMatch::TYPE_EXACT],
            'leading praise number' => ["299 'How sweet the name of Jesus sounds'", 1, SongTitleMatch::TYPE_PRAISE_NUMBER],
            'letter-variant praise number' => ["46a 'Crown him'", 2, SongTitleMatch::TYPE_PRAISE_NUMBER],
            'plain title vs number-suffixed key' => ['All heaven declares', 3, SongTitleMatch::TYPE_STRIPPED_NUMBER],
            'NIP marker without a number' => ["NIP 'Speak O Lord'", 8, SongTitleMatch::TYPE_STRIPPED_NUMBER],
            'NIP with mismatched quote' => ["NIP When I was lost'", 9, SongTitleMatch::TYPE_STRIPPED_NUMBER],
            'alternate title behind NIP' => ["NIP 'His mercy is more'", 4, SongTitleMatch::TYPE_ALTERNATE_TITLE],
            'decorated alternate title' => ['#203 All Creatures Of Our God And King', 6, SongTitleMatch::TYPE_ALTERNATE_TITLE],
            'openlp @-segment probes the alternate search text' => ['unknown working title@when i fear my faith will fail', 5, SongTitleMatch::TYPE_EXACT],
            'alternate title from openlp search title' => ['he will hold me fast@when i fear my faith will fail', 5, SongTitleMatch::TYPE_ALTERNATE_TITLE],
            'communion song prefix with no space' => ["Communion song:344 'There is a Redeemer", 10, SongTitleMatch::TYPE_PRAISE_NUMBER],
            'communion song prefix with en-dash' => ["Communion song – 1118 'Behold the Lamb'", 22, SongTitleMatch::TYPE_PRAISE_NUMBER],
            // A qualified "hymn" label has to strip exactly as a qualified "song" label does.
            // The alternation used to bind the qualifier words to `song` alone, so the two
            // lines above resolved and these did not — 125 of the 283 unresolved titles in the
            // 2026-08-16 item ground truth carried a label this rung should have seen through.
            'communion hymn prefix with en-dash' => ["Communion hymn – 1118 'Behold the Lamb'", 22, SongTitleMatch::TYPE_PRAISE_NUMBER],
            'final hymn prefix with a colon' => ["Final hymn: 344 'There is a Redeemer'", 10, SongTitleMatch::TYPE_PRAISE_NUMBER],
            'closing hymn prefix with a dash' => ['Closing Hymn - All heaven declares', 3, SongTitleMatch::TYPE_STRIPPED_NUMBER],
            // "Carol" is the third role word the orders use, and it is followed by the quoted
            // title with no separator at all.
            'carol label before a quoted title' => ["Carol 'Speak O Lord'", 8, SongTitleMatch::TYPE_STRIPPED_NUMBER],
            'hymn label before a bare praise number' => ['Hymn 299', 1, SongTitleMatch::TYPE_PRAISE_NUMBER],
            'possessive song label with trailing parenthetical' => ["Jade's song: See him in Jerusalem (Jerusalem)", 11, SongTitleMatch::TYPE_EXACT],
            'leading hash before praise number' => ['#1190 To see the king of heaven fall (Gethsemane)', 12, SongTitleMatch::TYPE_PRAISE_NUMBER],
            'title starting with the word Praise is not stripped' => ['Praise to the Lord, the Almighty', 13, SongTitleMatch::TYPE_LOOSE_TITLE],
            'stray punctuation in the email title' => ['Come , let us join our cheerful songs', 17, SongTitleMatch::TYPE_LOOSE_TITLE],
            'oh variant of an o-prefixed title' => ['Oh come all ye faithful', 18, SongTitleMatch::TYPE_LOOSE_TITLE],
            'trailing parenthetical performance note' => ['From the breaking of the dawn (no bridge)', 19, SongTitleMatch::TYPE_EXACT],
            'parenthetical content is the alternate title' => ["NIP 'Here the songs of angels rise' (comfort and joy)", 7, SongTitleMatch::TYPE_ALTERNATE_TITLE],
            'first line differing from the title' => ['In Christ alone my hope is found', 20, SongTitleMatch::TYPE_FIRST_LINE],
            'praise-no label resolved by number when the title differs' => ['Praise no 452: Messiah dies', 26, SongTitleMatch::TYPE_PRAISE_NUMBER],
            // A NIP number is a supplement number, not a Praise! book number — this must
            // resolve by title, never via the praise_number map (26 has praise_number 452).
            'nip number is not read as a praise number' => ["NIP 452 'Speak O Lord'", 8, SongTitleMatch::TYPE_STRIPPED_NUMBER],
            'hymn label prefix' => ['Hymn: All heaven declares', 3, SongTitleMatch::TYPE_STRIPPED_NUMBER],
            'two-word song label' => ["Alternative final song: NIP 'Speak O Lord'", 8, SongTitleMatch::TYPE_STRIPPED_NUMBER],
            'bullet before a hymn label' => ['- Hymn: All heaven declares', 3, SongTitleMatch::TYPE_STRIPPED_NUMBER],
            'planning duration before a song label' => ["[3m] Song: 344 'There is a Redeemer'", 10, SongTitleMatch::TYPE_PRAISE_NUMBER],
            'planning duration and bullet before a hymn label' => ['[3m] - Hymn 299', 1, SongTitleMatch::TYPE_PRAISE_NUMBER],
            'shared first line resolved by the title tier' => ['All creatures of our God and King', 6, SongTitleMatch::TYPE_STRIPPED_NUMBER],
        ];
    }

    #[Test]
    #[DataProvider('unresolvableProvider')]
    public function it_leaves_unresolvable_titles_unmatched(string $searchTitle): void
    {
        $this->assertNull($this->resolver()->resolve($searchTitle));
    }

    /**
     * Why widening the label rung is safe on the live linking path: the raw title is probe zero,
     * so every deterministic rung sees it before any cleaned form. A title that resolves today
     * therefore resolves to the same song afterwards, and the widened rung can only add matches
     * the resolver previously missed — it can never redirect one it already had.
     */
    #[Test]
    public function it_never_lets_the_label_rung_override_a_title_that_already_resolved(): void
    {
        $resolver = $this->resolver();

        foreach ([
            'Song Of The Redeemed' => 27,
            'Carol Of The Bells' => 28,
            'Come, Let Us Join Our Cheerful Songs' => 17,
        ] as $title => $expectedSongId) {
            $match = $resolver->resolve($title);

            $this->assertNotNull($match, "Expected a match for: {$title}");
            $this->assertSame($expectedSongId, $match->songId, "Wrong song for: {$title}");
        }
    }

    public static function unresolvableProvider(): array
    {
        return [
            'ambiguous title shared by two hymns' => ['Abide with me'],
            'numeric title does not read as a praise number' => ['10,000 Reasons'],
            'placeholder too short for fuzzy' => ['Song'],
            'single word too short for fuzzy' => ['Grace'],
            'empty string' => [''],
            'unknown song' => ['A hymn the library has never catalogued'],
            // The #203 hymn and its "(adapted)" sibling share a first line, so a typo'd
            // probe scores both songs identically and the fuzzy margin guard refuses.
            'typo ambiguous between twin catalog entries' => ['All creature of our God and king'],
            // 0.88 similar to "My Heart Is Full" but a different song — the threshold must
            // refuse near-miss titles that are not word-boundary truncations.
            'similar but distinct title below the threshold' => ['My heart is filled'],
        ];
    }

    #[Test]
    public function it_fuzzy_matches_a_typo_with_confidence_and_margin(): void
    {
        $match = $this->resolver()->resolve('From the braking of the dawn');

        $this->assertNotNull($match);
        $this->assertSame(19, $match->songId);
        $this->assertSame(SongTitleMatch::TYPE_FUZZY, $match->matchType);
        $this->assertGreaterThanOrEqual(0.85, $match->confidence);
        $this->assertLessThan(1.0, $match->confidence);
    }

    #[Test]
    public function it_fuzzy_matches_a_misheard_first_word(): void
    {
        $match = $this->resolver()->resolve("NIP 'Here the songs of angels rise'");

        $this->assertNotNull($match);
        $this->assertSame(7, $match->songId);
        $this->assertSame(SongTitleMatch::TYPE_FUZZY, $match->matchType);
    }

    #[Test]
    public function it_fuzzy_matches_a_truncated_title_via_prefix_containment(): void
    {
        $match = $this->resolver()->resolve("How deep the Father's love");

        $this->assertNotNull($match);
        $this->assertSame(23, $match->songId);
        $this->assertSame(SongTitleMatch::TYPE_FUZZY, $match->matchType);
        $this->assertSame(0.98, $match->confidence);
    }

    #[Test]
    public function it_fuzzy_matches_an_extended_title_via_reverse_containment(): void
    {
        $match = $this->resolver()->resolve('All Praise to Him, the Lord of light');

        $this->assertNotNull($match);
        $this->assertSame(24, $match->songId);
        $this->assertSame(SongTitleMatch::TYPE_FUZZY, $match->matchType);
        $this->assertSame(0.98, $match->confidence);
    }

    #[Test]
    public function it_does_not_fuzzy_match_when_disabled(): void
    {
        $resolver = $this->resolver(['fuzzy_enabled' => false]);

        $this->assertNull($resolver->resolve('From the braking of the dawn'));
    }

    #[Test]
    public function it_does_not_fuzzy_match_below_the_threshold(): void
    {
        $resolver = $this->resolver(['fuzzy_threshold' => 0.99]);

        $this->assertNull($resolver->resolve('From the braking of the dawn'));
    }

    #[Test]
    public function it_does_not_fuzzy_match_when_the_runner_up_is_too_close(): void
    {
        // Both Abide With Me hymns share every loose key, so the best two scores tie exactly.
        $this->assertNull($this->resolver()->resolve('Abide with me evening hymn'));
    }

    #[Test]
    public function it_names_the_catalogue_title_behind_a_match(): void
    {
        $resolver = $this->resolver();
        $match = $resolver->resolve('299');

        $this->assertInstanceOf(SongTitleMatch::class, $match);
        $this->assertSame('How Sweet The Name Of Jesus Sounds #299', $resolver->catalogueTitle($match->songId));
    }

    #[Test]
    public function it_has_no_catalogue_title_for_a_row_that_carried_none(): void
    {
        // Rows are allowed to omit a title — the songs table forbids a blank one, but
        // fromRows() takes plain arrays, so callers must cope with the gap.
        $resolver = SongTitleResolver::fromRows([
            ['id' => 1, 'canonical_key' => 'speak o lord'],
        ]);

        $this->assertNull($resolver->catalogueTitle(1));
        $this->assertNull($resolver->catalogueTitle(999));
    }
}
