<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\PlaceholderSermonTitle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlaceholderSermonTitleTest extends TestCase
{
    /**
     * The exact title every historic-video pilot sermon was left holding. All
     * fifteen kept a machine title while good analysis titles sat unused, so
     * each one is named here rather than described.
     */
    #[Test]
    #[DataProvider('pilotTitles')]
    public function it_recognises_every_title_the_pilot_left_behind(string $title): void
    {
        $this->assertTrue(PlaceholderSermonTitle::matches($title), $title);
    }

    /** @return iterable<string, array{string}> */
    public static function pilotTitles(): iterable
    {
        yield 'date, church and backup suffix' => ['Sunday 22 March 2020   Crockenhill Baptist Church [Youtube Backup]'];
        yield 'date and backup suffix' => ['Sunday 8Th February 2026 [Youtube Backup]'];
        yield 'service slot and date' => ['Morning    Sunday 8 May 2022 [Youtube Backup]'];
        yield 'occasion and partial date' => ['Joint 5 Churches    Sunday 5Th September [Youtube Backup]'];
        yield 'date without a year' => ['Sunday 24 May [Youtube Backup]'];
        yield 'day and month only' => ['3 May   [Youtube Backup]'];
        yield 'bare morning slot' => ['Morning'];
        yield 'bare evening slot' => ['Evening'];
    }

    #[Test]
    #[DataProvider('curatedTitles')]
    public function it_leaves_an_editorial_title_alone(string $title): void
    {
        $this->assertFalse(PlaceholderSermonTitle::matches($title), $title);
    }

    /**
     * Overreach here would let the model overwrite a title someone chose, so
     * these guard the shapes that sit closest to the placeholder rules: real
     * titles opening on a service word, on a weekday, and on a date.
     *
     * @return iterable<string, array{string}>
     */
    public static function curatedTitles(): iterable
    {
        yield 'analysis title' => ['Jesus is the way, the truth and the life'];
        yield 'opens on a service word' => ['Morning Glory'];
        yield 'evening in a real title' => ['Evening Star'];
        yield 'opens on a weekday' => ['Sunday Best'];
        yield 'carries a date' => ['Christmas Day 25 December'];
        yield 'names a book' => ['The Book of Job'];
        yield 'mentions a backup' => ['Backup plans and the providence of God'];
    }

    #[Test]
    public function it_treats_an_empty_or_whitespace_title_as_a_placeholder(): void
    {
        $this->assertTrue(PlaceholderSermonTitle::matches(''));
        $this->assertTrue(PlaceholderSermonTitle::matches('   '));
    }
}
