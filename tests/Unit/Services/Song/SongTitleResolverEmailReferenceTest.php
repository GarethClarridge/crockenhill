<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Song;

use App\Data\SongTitleMatch;
use App\Services\Song\SongTitleResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * IC3 item 9 — reading the song reference out of an order-of-service email line.
 *
 * The catalogue was never the limiting factor on the Email side: of the 247 unresolved song
 * assertions in the catalogued rehearsal replay, all 247 were Email and a third of them named
 * a song the catalogue already held. Every line below is verbatim from that corpus, so each
 * case records a real shape rather than an invented one, and the near misses are pinned so a
 * later widening cannot quietly reintroduce them.
 */
class SongTitleResolverEmailReferenceTest extends TestCase
{
    private const Rows = [
        ['id' => 313, 'canonical_key' => 'let us love and sing and wonder 313', 'title' => 'Let Us Love And Sing And Wonder #313', 'praise_number' => '313'],
        ['id' => 427, 'canonical_key' => 'i will sing of the lamb 427', 'title' => 'I Will Sing Of The Lamb #427', 'praise_number' => '427'],
        ['id' => 96, 'canonical_key' => 'o sing a new song 096', 'title' => 'O Sing A New Song #096', 'praise_number' => '096'],
        ['id' => 315, 'canonical_key' => 'meekness and majesty 315', 'title' => 'Meekness And Majesty #315', 'praise_number' => '315'],
        ['id' => 894, 'canonical_key' => 'come o fount of every blessing 894', 'title' => 'Come O Fount Of Every Blessing #894', 'praise_number' => '894'],
        ['id' => 618, 'canonical_key' => 'facing a task unfinished 618', 'title' => 'Facing A Task Unfinished #618', 'praise_number' => '618'],
        ['id' => 772, 'canonical_key' => 'amazing grace 772', 'title' => 'Amazing Grace #772', 'praise_number' => '772'],
        // The single-digit Praise! numbers that a list position was being read as.
        ['id' => 1, 'canonical_key' => 'happy the people who refuse 1', 'title' => 'Happy The People Who Refuse #1', 'praise_number' => '1'],
        ['id' => 3, 'canonical_key' => 'o lord how many enemies 3', 'title' => 'O Lord How Many Enemies #3', 'praise_number' => '3'],
        // Songs the hymnbook does not number.
        ['id' => 900, 'canonical_key' => 'oceans', 'title' => 'Oceans'],
        ['id' => 901, 'canonical_key' => 'see what a morning', 'title' => 'See What A Morning'],
        ['id' => 902, 'canonical_key' => 'creator god', 'title' => 'Creator God'],
        ['id' => 903, 'canonical_key' => 'in christ alone', 'title' => 'In Christ Alone'],
        ['id' => 904, 'canonical_key' => 'there is a hope', 'title' => 'There Is A Hope'],
        ['id' => 905, 'canonical_key' => 'good christian men rejoice', 'title' => 'Good Christian Men Rejoice'],
        ['id' => 906, 'canonical_key' => 'beneath the cross of jesus', 'title' => 'Beneath the Cross of Jesus'],
        ['id' => 907, 'canonical_key' => 'great is thy faithfulness', 'title' => 'Great Is Thy Faithfulness'],
        ['id' => 908, 'canonical_key' => 'it is well with my soul', 'title' => 'It Is Well With My Soul'],
        // The near miss the quoted-run rung must not resolve to: a song whose title is a
        // plausible reading of scheduling prose.
        ['id' => 909, 'canonical_key' => 'to be a pilgrim', 'title' => 'To Be A Pilgrim'],
    ];

    private function resolver(array $options = []): SongTitleResolver
    {
        return SongTitleResolver::fromRows(self::Rows, $options);
    }

    /** @return iterable<string, array{string, int}> */
    public static function readableReferences(): iterable
    {
        // Scheduling prose wraps the title; the quotes say where it stops.
        yield 'prose wrapper around a numbered title' => ["final hymn for evening - 313 'let us love and sing and wonder' (would go well to gounod no.326)", 313];
        yield 'prose wrapper written as a sentence' => ['my final hymn for the morning is 427 ‘i will sing of the lamb’', 427];
        yield 'welcome sheet is not a role label' => ['welcome sheet: ‘see what a morning’', 901];
        yield 'quoted title inside a scheduling note' => ['playing b4 the service ‘oceans’. lyrics and music on you tube', 900];
        yield 'another hymnal numbers the quoted title' => ['mp196 ‘good christian men rejoice’', 905];

        // A full stop separates the role word from its number as readily as a colon does.
        yield 'full stop after the role word' => ['hymn. 96 (lower setting)', 96];

        // A number the writer marked as a reference is one, wherever it sits.
        yield 'parenthesised hash number' => ['song amazing grace (#772)', 772];
        yield 'enumerated praise reference' => ['4. praise no 618 - facing a task unfinished', 618];

        // Shorthand the catalogue spells out.
        yield 'ampersand for and' => ['hymn: *meekness & majesty* (p315)', 315];
        yield 'contraction expanded' => ["hymn nip 'there's a hope'", 904];

        // The marker means the same thing wherever the operator wrote it.
        yield 'trailing absence marker' => ['beneath the cross of jesus nip', 906];
        yield 'parenthesised absence marker after a bare role word' => ['song in christ alone (nip)', 903];
        yield 'role word immediately before the marker' => ['hymn nip creator god', 902];

        // A dash-separated tail is an attribution, not part of the title.
        yield 'attribution after a dash' => ['creator god - ben slee', 902];
    }

    #[Test]
    #[DataProvider('readableReferences')]
    public function it_reads_the_song_out_of_a_decorated_email_line(string $line, int $expected): void
    {
        $match = $this->resolver()->resolve($line);

        self::assertInstanceOf(SongTitleMatch::class, $match, "Expected '{$line}' to resolve.");
        self::assertSame($expected, $match->songId, "Wrong song for: {$line}");
    }

    /**
     * The plan's named trap, and it was live: "Song 1", "Song 3" and "Song 5" number the
     * service's songs, and reading those digits as Praise! references linked twelve corpus
     * lines to *Happy The People Who Refuse #1* and *O Lord How Many Enemies #3* — songs
     * nobody had mentioned. A wrong link is worse than a null one here.
     *
     * @return iterable<string, array{string, ?int}>
     */
    public static function listPositions(): iterable
    {
        yield 'index then title' => ['song 1 - oceans https://youtu.be/mpq3waqlvki', 900];
        yield 'index then title, no separator' => ['song 3 to god be the glory (#676)', null];
        yield 'index with a parenthesised reference' => ['song 1 - come thou fount of every blessing (#894)', 894];
        yield 'index and no song at all' => ["song 5 - mark's choice", null];
        // "songs 2+3" enumerates two positions; neither digit is a reference, and the line
        // names two songs, so it must resolve to neither.
        yield 'compound index' => ['songs 2+3 - who, o lord, could save themselves? (nip)', null];
        // A two- or three-digit number after the same role word is a real reference.
        yield 'three-digit reference is not an index' => ["song 313 'let us love and sing and wonder'", 313];
    }

    #[Test]
    #[DataProvider('listPositions')]
    public function a_song_list_position_is_never_read_as_a_hymn_number(string $line, ?int $expected): void
    {
        $match = $this->resolver()->resolve($line);

        self::assertSame($expected, $match?->songId, "Wrong resolution for: {$line}");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableReferences(): iterable
    {
        // Two quoted runs are a choice the writer had not yet made. Resolving the first would
        // pick a winner the source never picked.
        yield 'a choice between three songs' => ["songs: either 'great is thy faithfulness', 'amazing grace' or 'it is well with my soul' (ollie can't make up his mind!!! )"];
        // Lines that name no specific song must keep resolving to nothing.
        yield 'names a chooser, not a song' => ['hymn - gareth to choose'];
        yield 'defers the choice entirely' => ['2 songs from holiday club (to follow) - children and yp to do actions'];
    }

    #[Test]
    #[DataProvider('unreadableReferences')]
    public function it_refuses_lines_that_do_not_name_one_song(string $line): void
    {
        self::assertNull($this->resolver()->resolve($line));
    }

    /**
     * The role-word rungs are widened here, so the existing guarantee is re-asserted against
     * the new shapes: a catalogued title that opens with a role word still resolves to itself.
     */
    #[Test]
    public function widening_the_label_rung_never_redirects_a_title_that_already_resolves(): void
    {
        $resolver = $this->resolver();

        foreach (['To Be A Pilgrim' => 909, 'In Christ Alone' => 903, 'Creator God' => 902] as $title => $expected) {
            self::assertSame($expected, $resolver->resolve($title)?->songId, "Wrong song for: {$title}");
        }
    }
}
