<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Support\BibleCanon;
use TechWilk\BibleVerseParser\BiblePassageParser;
use Throwable;

/**
 * Decides whether a proposed sermon series is corroborated by the sermon itself.
 *
 * The historic-video pilot showed why this matters. The model sees a list of
 * series names with no way to check them, and it reached for whatever read
 * plausibly: a September evening on John 19 joined "Easter: Good Friday", and a
 * sermon on Genesis 44 joined "Abraham", a series that ends at Genesis 25.
 *
 * Only one corroboration is available without another paid call: a series named
 * after a book of the Bible is corroborated when the sermon expounds that book.
 * Every correct pilot assignment passes that test — John, Job, Philippians,
 * Exodus and 2 Peter — and every wrong one fails it. Date adjacency cannot
 * stand in for it: the archive's series members predate the video corpus by
 * years, so the nearest sibling of even a correct assignment is thousands of
 * days away.
 *
 * Thematic and occasional series ("Hope In Hurtful Times") cannot be
 * corroborated this way and are left for review rather than guessed at.
 */
class SermonSeriesCorroboration
{
    /**
     * Prefixes an editor puts in front of a book name when titling a series.
     *
     * Matched longest-first so "The Book of" is stripped before "The".
     *
     * @var list<string>
     */
    private const BOOK_SERIES_PREFIXES = [
        'the letters of',
        'the letter of',
        'the gospel of',
        'the book of',
        'the epistle of',
        'letters of',
        'letter of',
        'gospel of',
        'book of',
        'epistle of',
        'studies in',
        'the',
    ];

    public function __construct(
        private readonly BibleCanon $canon,
        private readonly BiblePassageParser $parser,
    ) {}

    /**
     * Whether the sermon's Scripture reference proves it belongs to the series.
     *
     * False covers both "the series is not a book series" and "it is, but the
     * sermon expounds a different book", because neither is evidence enough to
     * write the field without a human looking.
     */
    public function corroborates(?string $series, ?string $reference): bool
    {
        $seriesBook = $this->seriesBook($series);

        if ($seriesBook === null) {
            return false;
        }

        return $this->referenceBook($reference) === $seriesBook;
    }

    /** The Bible book a series is named after, or null when it names none. */
    public function seriesBook(?string $series): ?string
    {
        if (! is_string($series)) {
            return null;
        }

        $candidate = trim(preg_replace('/\s+/', ' ', $series) ?? $series);

        foreach (self::BOOK_SERIES_PREFIXES as $prefix) {
            if (mb_stripos($candidate, $prefix.' ') === 0) {
                $candidate = trim(mb_substr($candidate, mb_strlen($prefix) + 1));

                break;
            }
        }

        return $candidate !== '' && $this->canon->hasBook($candidate) ? $candidate : null;
    }

    /** The Bible book a reference expounds, or null when it does not parse. */
    public function referenceBook(?string $reference): ?string
    {
        if (! is_string($reference) || trim($reference) === '') {
            return null;
        }

        try {
            $passages = $this->parser->parse($reference);
        } catch (Throwable) {
            return null;
        }

        return $passages === [] ? null : $passages[0]->from()->book()->name();
    }
}
