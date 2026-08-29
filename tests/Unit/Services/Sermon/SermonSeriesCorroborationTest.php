<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sermon;

use App\Services\Sermon\SermonSeriesCorroboration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonSeriesCorroborationTest extends TestCase
{
    private SermonSeriesCorroboration $corroboration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->corroboration = app(SermonSeriesCorroboration::class);
    }

    /**
     * The exact series the pilot's analysis proposed. Five were right and three
     * were wrong, and the same rule has to sort them without another paid call.
     */
    #[Test]
    #[DataProvider('pilotSeriesAssignments')]
    public function it_sorts_the_pilot_series_assignments(
        string $series,
        ?string $reference,
        bool $corroborated,
    ): void {
        $this->assertSame(
            $corroborated,
            $this->corroboration->corroborates($series, $reference),
            "{$series} / ".($reference ?? 'no reference'),
        );
    }

    /** @return iterable<string, array{string, string|null, bool}> */
    public static function pilotSeriesAssignments(): iterable
    {
        yield 'gospel series matching its book' => ['The Gospel of John', 'John 14:1-7', true];
        yield 'book series matching its book' => ['The Book of Job', 'Job 1:1-12', true];
        yield 'letter series matching its book' => ['The Book of Philippians', 'Philippians 1:3-8', true];
        yield 'old testament book series' => ['The Book of Exodus', 'Exodus 15:1-21', true];
        yield 'numbered book series' => ['The Book of 2 Peter', '2 Peter 1:12-21', true];
        yield 'occasion series on the wrong date' => ['Easter: Good Friday', 'John 19:15-30', false];
        yield 'person series beyond its span' => ['Abraham', 'Genesis 44:18-34', false];
        yield 'thematic series with no reference' => ['Hope In Hurtful Times', null, false];
    }

    #[Test]
    public function it_refuses_a_book_series_the_reference_contradicts(): void
    {
        $this->assertFalse($this->corroboration->corroborates('The Gospel of John', 'Luke 4:1-2'));
    }

    #[Test]
    public function it_refuses_a_book_series_whose_reference_will_not_parse(): void
    {
        $this->assertFalse($this->corroboration->corroborates('The Book of Job', 'not a reference'));
        $this->assertFalse($this->corroboration->corroborates('The Book of Job', '   '));
    }

    #[Test]
    public function it_resolves_the_book_a_series_is_named_after(): void
    {
        $this->assertSame('John', $this->corroboration->seriesBook('The Gospel of John'));
        $this->assertSame('Exodus', $this->corroboration->seriesBook('Studies in Exodus'));
        $this->assertSame('Romans', $this->corroboration->seriesBook('Romans'));
        $this->assertNull($this->corroboration->seriesBook('Hope In Hurtful Times'));
        $this->assertNull($this->corroboration->seriesBook(null));
    }
}
