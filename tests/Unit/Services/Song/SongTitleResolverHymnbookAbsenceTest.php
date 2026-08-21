<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Song;

use App\Data\SongTitleMatch;
use App\Services\Song\SongTitleResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "NIP" is the projectionist's shorthand for *not in Praise!*, so it asserts the song is
 * absent from the hymnbook and therefore says which catalogue row is meant. Every case here
 * is taken from the catalogued rehearsal corpus.
 */
class SongTitleResolverHymnbookAbsenceTest extends TestCase
{
    private const Rows = [
        // The pair that made the shepherd conflict: same opening line, four centuries apart.
        ['id' => 918, 'canonical_key' => 'the lords my shepherd 23b', 'title' => "The Lord's My Shepherd #23B",
            'praise_number' => '23B', 'first_line_key' => "the lord's my shepherd, i'll not want;"],
        ['id' => 919, 'canonical_key' => 'the lords my shepherd i will trust in you alone',
            'title' => "The Lord's My Shepherd (I Will Trust In You Alone)", 'praise_number' => null,
            'first_line_key' => "the lord's my shepherd, i'll not want."],
        // The catalogue names the modern setting more fully than the email does.
        ['id' => 642, 'canonical_key' => 'my hope is built 779', 'title' => 'My Hope Is Built #779', 'praise_number' => '779'],
        ['id' => 643, 'canonical_key' => 'my hope is built on nothing less', 'title' => 'My hope is built on nothing less', 'praise_number' => null],
        // Near misses whose numbered rows are already correct.
        ['id' => 676, 'canonical_key' => 'to god be the glory 676', 'title' => 'To God Be The Glory #676', 'praise_number' => '676'],
        ['id' => 999, 'canonical_key' => 'thine be the glory', 'title' => 'Thine Be The Glory', 'praise_number' => null],
        ['id' => 627, 'canonical_key' => 'tell all the world of jesus 627', 'title' => 'Tell All The World Of Jesus #627', 'praise_number' => '627'],
        ['id' => 998, 'canonical_key' => 'tell me the stories of jesus', 'title' => 'Tell Me The Stories Of Jesus', 'praise_number' => null],
    ];

    private function resolver(): SongTitleResolver
    {
        return SongTitleResolver::fromRows(self::Rows);
    }

    /** @return iterable<string, array{string, int}> */
    public static function hymnbookAbsentLines(): iterable
    {
        yield 'exact title, numbered twin exists' => ['NIP ‘The Lord’s my Shepherd’', 919];
        yield 'email names a prefix of the catalogue title' => ['NIP ‘my hope is built’', 643];
        yield 'prefix with a variant parenthetical' => ["NIP 'my hope is built (cornerstone)", 643];
    }

    #[Test]
    #[DataProvider('hymnbookAbsentLines')]
    public function a_nip_line_prefers_the_song_the_hymnbook_does_not_number(string $line, int $expected): void
    {
        $match = $this->resolver()->resolve($line);

        self::assertInstanceOf(SongTitleMatch::class, $match);
        self::assertSame($expected, $match->songId);
        self::assertSame(SongTitleMatch::TYPE_HYMNBOOK_ABSENT, $match->matchType);
    }

    /**
     * Fuzzy resemblance is not evidence of absence. Both of these pair with a different hymn
     * that happens to share words, and both numbered rows are the correct answer — so the
     * preference must never widen past exact and prefix matching.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function nearMissLines(): iterable
    {
        yield 'to god be the glory is not thine be the glory' => ["NIP 'to god be the glory'", 676];
        yield 'tell all the world is not tell me the stories' => ["NIP '627 'tell all the world of jesus'", 627];
    }

    #[Test]
    #[DataProvider('nearMissLines')]
    public function a_merely_similar_unnumbered_song_is_never_preferred(string $line, int $expected): void
    {
        $match = $this->resolver()->resolve($line);

        self::assertInstanceOf(SongTitleMatch::class, $match);
        self::assertSame($expected, $match->songId);
        self::assertNotSame(SongTitleMatch::TYPE_HYMNBOOK_ABSENT, $match->matchType);
    }

    /** Without the marker the hymnbook row remains the answer, so ordinary lines are untouched. */
    #[Test]
    public function a_line_without_the_marker_still_resolves_to_the_numbered_song(): void
    {
        $match = $this->resolver()->resolve("The Lord's My Shepherd");

        self::assertInstanceOf(SongTitleMatch::class, $match);
        self::assertSame(918, $match->songId);
    }

    /** Resolution fills gaps; a NIP line naming nothing unnumbered keeps its existing answer. */
    #[Test]
    public function a_nip_line_with_no_unnumbered_candidate_falls_through_unchanged(): void
    {
        $match = $this->resolver()->resolve('NIP ‘To God Be The Glory’');

        self::assertInstanceOf(SongTitleMatch::class, $match);
        self::assertSame(676, $match->songId);
    }

    /**
     * The corroboration this exists to restore: an Email plan marked NIP and an independent
     * OpenLP archive of the same service must reach the same song.
     */
    #[Test]
    public function an_email_nip_line_and_an_openlp_title_reach_the_same_song(): void
    {
        $resolver = $this->resolver();

        $email = $resolver->resolve('NIP ‘The Lord’s my Shepherd’');
        $openLp = $resolver->resolve("The Lord's My Shepherd (I Will Trust In You Alone)");

        self::assertInstanceOf(SongTitleMatch::class, $email);
        self::assertInstanceOf(SongTitleMatch::class, $openLp);
        self::assertSame($openLp->songId, $email->songId);
    }
}
