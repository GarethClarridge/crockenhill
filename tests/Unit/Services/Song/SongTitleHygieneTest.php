<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Song;

use App\Enums\SongTitleDefect;
use App\Enums\SongTitleHygieneVerdict;
use App\Services\Song\SongTitleHygiene;
use App\Services\Song\SongTitleResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every title in these cases is taken verbatim from the 283 unresolved staged song items in the
 * 2026-08-16 item ground truth, so the classifier is held to the corpus it has to work on rather
 * than to invented examples.
 */
class SongTitleHygieneTest extends TestCase
{
    /**
     * @return list<array{0:string,1:SongTitleHygieneVerdict,2:SongTitleDefect}>
     */
    public static function corpusCases(): array
    {
        return [
            // Decorated: the reference is intact, the resolver's cleaning does not reach the wrapper.
            'role label with an en dash' => ['Communion hymn – NIP ‘Behold the Lamb’', SongTitleHygieneVerdict::Decorated, SongTitleDefect::RoleLabel],
            'role label with a colon' => ['Final hymn: NIP ‘The price is paid’', SongTitleHygieneVerdict::Decorated, SongTitleDefect::RoleLabel],
            'carol is a role word too' => ['Carol ‘O come all you faithful’', SongTitleHygieneVerdict::Decorated, SongTitleDefect::RoleLabel],
            'bare list bullet' => ['- 203', SongTitleHygieneVerdict::Decorated, SongTitleDefect::BulletPrefix],
            'planning duration marker' => ['[3m] Song: #455 Christ is risen! Hallelujah!', SongTitleHygieneVerdict::Decorated, SongTitleDefect::DurationPrefix],
            'trailing source credit' => ['Carol ‘O Holy night’ – music.ministry.org', SongTitleHygieneVerdict::Decorated, SongTitleDefect::AttributionSuffix],
            'markdown emphasis' => ['*Hymn:* *King of kings, Majesty*', SongTitleHygieneVerdict::Decorated, SongTitleDefect::MarkupResidue],

            // Defective: the text does not carry one intact reference.
            'cut off mid-title' => ['Communion hymn – 429 ‘It is a thing most', SongTitleHygieneVerdict::Defective, SongTitleDefect::Truncated],
            'cut off mid-parenthetical' => ['NIP ‘When I survey’ (modern tune – talk to you', SongTitleHygieneVerdict::Defective, SongTitleDefect::Truncated],
            'wrapped line tail' => ['from praise and then the two new verses I think, to get the harvest', SongTitleHygieneVerdict::Defective, SongTitleDefect::LineFragment],
            'conversation captured as a title' => ['Dad tells me you have the laptop so here are the morning hymns. See you later. xx', SongTitleHygieneVerdict::Defective, SongTitleDefect::ProseBleed],
            'sentence about a song' => ["My final hymn for Sunday morning is NIP 'A new commandment'.", SongTitleHygieneVerdict::Defective, SongTitleDefect::ProseBleed],
            'two songs in one item' => ['- 100a + 191', SongTitleHygieneVerdict::Defective, SongTitleDefect::MultipleSongs],

            // Not a title: nothing was stated for anyone to extract.
            'bare role word' => ['Song', SongTitleHygieneVerdict::NotATitle, SongTitleDefect::Placeholder],
            'choice deferred to a person' => ['Hymn - Gareth to choose', SongTitleHygieneVerdict::NotATitle, SongTitleDefect::Placeholder],
            'choice not yet made' => ['Song - still to be chosen', SongTitleHygieneVerdict::NotATitle, SongTitleDefect::Placeholder],
            'chooser named in an aside' => ['Hymn: (Mark to choose)', SongTitleHygieneVerdict::NotATitle, SongTitleDefect::Placeholder],
            'another item type entirely' => ['NCC Q43', SongTitleHygieneVerdict::NotATitle, SongTitleDefect::NotASongItem],
        ];
    }

    #[Test]
    #[DataProvider('corpusCases')]
    public function it_classifies_a_corpus_title(string $title, SongTitleHygieneVerdict $verdict, SongTitleDefect $defect): void
    {
        $report = (new SongTitleHygiene)->inspect($title);

        $this->assertSame($verdict, $report->verdict, "Wrong verdict for: {$title}");
        $this->assertTrue($report->has($defect), "Expected {$defect->value} for: {$title}");
    }

    /**
     * @return list<array{0:string}>
     */
    public static function wellFormedTitles(): array
    {
        return [
            'a supplement song the catalogue may lack' => ["NIP 'Great big God'"],
            'a complete parenthetical aside' => ['NIP ‘Christ is Risen’ (new Easter song)'],
            'a copula inside the quoted title' => ["NIP 'Jesus is Lord, the cry that echoes'"],
            'a past-tense copula inside the quoted title' => ["Praise! 366 'Lord, you were rich'"],
            'a copula in an unquoted title' => ['Christ is risen! Hallelujah!'],
            'a title that merely opens with a role word' => ['Song of the Redeemed'],
        ];
    }

    /**
     * A clean title that fails to resolve is evidence about the catalogue. Flagging one as damaged
     * would send extraction work at a population no parser change can reach — which is the exact
     * misreading this classifier exists to prevent, so its false-positive behaviour is the part
     * worth pinning down.
     */
    #[Test]
    #[DataProvider('wellFormedTitles')]
    public function it_leaves_a_well_formed_title_alone(string $title): void
    {
        $report = (new SongTitleHygiene)->inspect($title);

        $this->assertSame(SongTitleHygieneVerdict::Clean, $report->verdict, "Wrongly flagged: {$title}");
        $this->assertSame([], $report->defects);
        $this->assertFalse($report->isNormalised());
    }

    /**
     * The point of normalisation is not a tidier string; it is that the resolver then finds the
     * song. Each of these is a title the resolver misses and resolves once the decoration is gone.
     *
     * The role-label family is deliberately absent: `SongTitleResolver::stripLeadingLabel()` was
     * fixed to strip it, which is exactly what `recovered_by_normalisation` was built to drive.
     * What remains here is decoration that sits *outside* the label — bullets, planning markers,
     * markdown emphasis — which the resolver still does not reach.
     */
    #[Test]
    public function it_normalises_decoration_into_something_the_resolver_can_match(): void
    {
        $hygiene = new SongTitleHygiene;
        $resolver = SongTitleResolver::fromRows([
            ['id' => 1, 'canonical_key' => 'amazing grace', 'title' => 'Amazing Grace'],
        ], ['fuzzy_enabled' => false]);

        foreach ([
            '- Hymn: Amazing Grace',
            '[3m] Song: Amazing Grace',
            '*Hymn:* *Amazing Grace*',
            '> Hymn: Amazing Grace',
        ] as $title) {
            $report = $hygiene->inspect($title);

            $this->assertNull($resolver->resolve($title), "Expected the resolver to miss: {$title}");
            $this->assertTrue($report->isNormalised(), "Expected normalisation to change: {$title}");
            $this->assertSame(
                1,
                $resolver->resolve($report->normalised)?->songId,
                "Expected the normalised title to resolve: {$title}",
            );
        }
    }

    /**
     * The resolver now strips a qualified role label itself, so the normaliser must agree with it
     * rather than compete: the two run the same idea over the same corpus, and a title the
     * resolver already handles must normalise to something that still resolves to the same song.
     */
    #[Test]
    public function it_agrees_with_the_resolver_on_labels_the_resolver_now_strips(): void
    {
        $hygiene = new SongTitleHygiene;
        $resolver = SongTitleResolver::fromRows([
            ['id' => 2, 'canonical_key' => 'behold the lamb', 'title' => 'Behold the Lamb'],
        ], ['fuzzy_enabled' => false]);

        foreach (['Communion hymn – NIP ‘Behold the Lamb’', 'Final hymn: Behold the Lamb'] as $title) {
            $this->assertSame(2, $resolver->resolve($title)?->songId, "Resolver should handle: {$title}");
            $this->assertSame(
                2,
                $resolver->resolve($hygiene->normalise($title))?->songId,
                "Normalisation must not lose a song the resolver already found: {$title}",
            );
        }
    }

    /**
     * Counting the apostrophe in "David's" as a closing quotation mark made the balance test read
     * intact titles as cut off, which would have moved a whole family of correct extractions into
     * the one bucket that drives parser work.
     */
    #[Test]
    public function it_does_not_read_an_apostrophe_as_an_unclosed_quotation(): void
    {
        $report = (new SongTitleHygiene)->inspect('Carol ‘Once in Royal David’s city’');

        $this->assertFalse($report->has(SongTitleDefect::Truncated));
        $this->assertSame(SongTitleHygieneVerdict::Decorated, $report->verdict);
        $this->assertSame('‘Once in Royal David’s city’', $report->normalised);
    }

    /**
     * A mis-decoded title is `Defective` because the email decoder owns the fault, not the
     * resolver — but the mis-decode is reversible, so its recovery still has to count.
     */
    #[Test]
    public function it_repairs_a_mis_decoded_title_even_though_the_verdict_is_defective(): void
    {
        $report = (new SongTitleHygiene)->inspect("Communion Hymn: NIP \u{00E2}\u{20AC}\u{02DC}Behold the Lamb\u{00E2}\u{20AC}\u{2122}");

        $this->assertSame(SongTitleHygieneVerdict::Defective, $report->verdict);
        $this->assertTrue($report->has(SongTitleDefect::Mojibake));
        $this->assertSame("NIP \u{2018}Behold the Lamb\u{2019}", $report->normalised);
        $this->assertTrue($report->isNormalised());
    }

    /**
     * The corpus contains lines that name a song and then point at a note about it. Reading the
     * pointer as a deferred choice would throw away a title the parser extracted correctly.
     */
    #[Test]
    public function it_does_not_call_a_named_song_a_deferred_choice(): void
    {
        $report = (new SongTitleHygiene)->inspect('Song: ‘To be a Pilgrim’ (see below)');

        $this->assertFalse($report->has(SongTitleDefect::Placeholder));
        $this->assertSame(SongTitleHygieneVerdict::Decorated, $report->verdict);
    }

    /**
     * `SongTitleResolver::stripLeadingLabel()` deliberately requires a separator so that a genuine
     * title opening with "Song" survives. The hygiene normaliser inherits that guard, relaxing it
     * only where what follows cannot be a title's second word.
     */
    #[Test]
    public function it_does_not_strip_a_role_word_that_opens_a_real_title(): void
    {
        $hygiene = new SongTitleHygiene;

        $this->assertSame('Song of the Redeemed', $hygiene->normalise('Song of the Redeemed'));
        $this->assertSame('Hymn to the Trinity', $hygiene->normalise('Hymn to the Trinity'));
    }

    #[Test]
    public function it_never_invents_a_title_for_damage_it_cannot_repair(): void
    {
        $hygiene = new SongTitleHygiene;

        // A truncated title has no correct completion, and a two-song item has no single answer.
        $this->assertSame('429 ‘It is a thing most', $hygiene->normalise('Communion hymn – 429 ‘It is a thing most'));
        $this->assertSame('100a + 191', $hygiene->normalise('- 100a + 191'));
    }

    #[Test]
    public function every_defect_family_routes_to_exactly_one_verdict(): void
    {
        foreach (SongTitleDefect::cases() as $defect) {
            $this->assertNotSame(
                SongTitleHygieneVerdict::Clean,
                $defect->verdict(),
                "{$defect->value} must not route to Clean; a clean title carries no defect.",
            );
        }
    }
}
