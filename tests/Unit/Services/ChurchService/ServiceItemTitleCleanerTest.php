<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ChurchService;

use App\Enums\ServiceSectionType;
use App\Services\ChurchService\ServiceItemTitleCleaner;
use App\Services\Scripture\ScriptureReferenceResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TechWilk\BibleVerseParser\BiblePassageParser;

class ServiceItemTitleCleanerTest extends TestCase
{
    private ServiceItemTitleCleaner $cleaner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleaner = new ServiceItemTitleCleaner(
            new ScriptureReferenceResolver(new BiblePassageParser),
        );
    }

    /**
     * @return array<string, array{0: string, 1: ServiceSectionType, 2: string}>
     */
    public static function crossReferenceProvider(): array
    {
        return [
            'trailing see-above' => ['Notices (see above)', ServiceSectionType::Notices, 'Notices'],
            'presentation pointer' => ['Family Talk – “Joel” (see PP)', ServiceSectionType::ChildrensTalk, 'Family Talk – “Joel”'],
            'square brackets' => ['Sermon [see overleaf]', ServiceSectionType::Sermon, 'Sermon'],
            'as-above form' => ['Communion (as above)', ServiceSectionType::Other, 'Communion'],
            'attached with trailing words' => ['Song (see attached sheet)', ServiceSectionType::Song, 'Song'],
            'song decoration is kept' => ['NIP ‘Holy, Spirit, living breath of God’ (see PP)', ServiceSectionType::Song, 'NIP ‘Holy, Spirit, living breath of God’'],
            'praise number is kept' => ['98 Sing to God', ServiceSectionType::Song, '98 Sing to God'],
        ];
    }

    #[Test]
    #[DataProvider('crossReferenceProvider')]
    public function it_drops_pointers_at_the_surrounding_document(
        string $rawTitle,
        ServiceSectionType $sectionType,
        string $expected,
    ): void {
        $this->assertSame($expected, $this->cleaner->displayTitle($rawTitle, $sectionType));
    }

    /**
     * @return array<string, array{0: string, 1: ServiceSectionType}>
     */
    public static function contentParentheticalProvider(): array
    {
        return [
            'names a person' => ['Prayer (led by Joel)', ServiceSectionType::Prayer],
            'names a passage' => ['Bible Reading (see Joshua 5)', ServiceSectionType::BibleReading],
            'target word inside a longer word' => ['Overcomer', ServiceSectionType::Song],
        ];
    }

    #[Test]
    #[DataProvider('contentParentheticalProvider')]
    public function it_keeps_a_parenthetical_that_names_content_rather_than_a_location(
        string $rawTitle,
        ServiceSectionType $sectionType,
    ): void {
        $this->assertSame($rawTitle, $this->cleaner->displayTitle($rawTitle, $sectionType));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function readingProvider(): array
    {
        return [
            'bible reading label' => ['Bible Reading: Joshua 5:13-6:27', 'Joshua 5:13-6:27'],
            'bare reading label' => ['Reading: 1 John 4:7-21', '1 John 4:7-21'],
            'testament abbreviation and dash' => ['OT Reading – Psalm 23', 'Psalm 23'],
            'numbered reading' => ['New Testament Reading 2: Luke 15', 'Luke 15'],
            'spacing is canonicalised' => ['Joshua 5:13 - 6:27', 'Joshua 5:13-6:27'],
            'multi-part reading is kept whole' => ['Bible Readings: Genesis 1 and John 1:1-14', 'Genesis 1, John 1:1-14'],
            'whole chapter collapses to the chapter' => ['Reading: Luke 15:1-32', 'Luke 15'],
            'label and pointer together' => ['Bible Reading: Joshua 5 (see PP)', 'Joshua 5'],
        ];
    }

    #[Test]
    #[DataProvider('readingProvider')]
    public function it_titles_a_reading_with_the_passage_alone(string $rawTitle, string $expected): void
    {
        $this->assertSame($expected, $this->cleaner->displayTitle($rawTitle, ServiceSectionType::BibleReading));
        $this->assertSame($expected, $this->cleaner->readingReference($expected, $rawTitle));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function labelOnlyReadingProvider(): array
    {
        return [
            'bible reading' => ['Bible Reading'],
            'reading' => ['Reading'],
            'label with an unparseable pointer' => ['Bible Reading (see Joshua 5)'],
        ];
    }

    #[Test]
    #[DataProvider('labelOnlyReadingProvider')]
    public function it_keeps_a_reading_line_that_names_no_passage(string $rawTitle): void
    {
        // Stripping the label off a line with no passage behind it would leave the item
        // with a blank or nonsensical title, and a blank title fails validation outright.
        $this->assertSame($rawTitle, $this->cleaner->displayTitle($rawTitle, ServiceSectionType::BibleReading));
        $this->assertNull($this->cleaner->readingReference($rawTitle));
    }

    #[Test]
    public function cleaning_an_already_clean_title_changes_nothing(): void
    {
        // The prefill cleans stored parses that the parser cleaned already, so this has to
        // hold or a title would drift every time a plan was reviewed.
        foreach ([
            ['Joshua 5:13-6:27', ServiceSectionType::BibleReading],
            ['Notices', ServiceSectionType::Notices],
            ['Living Hope', ServiceSectionType::Song],
            ['Closing prayer', ServiceSectionType::Prayer],
        ] as [$title, $sectionType]) {
            $once = $this->cleaner->displayTitle($title, $sectionType);

            $this->assertSame($title, $once);
            $this->assertSame($once, $this->cleaner->displayTitle($once, $sectionType));
        }
    }

    #[Test]
    public function it_falls_back_to_the_raw_line_when_cleaning_would_empty_the_title(): void
    {
        $this->assertSame('(see above)', $this->cleaner->displayTitle('(see above)', ServiceSectionType::Other));
    }

    #[Test]
    public function it_reads_a_reference_from_the_raw_line_when_the_title_has_none(): void
    {
        $this->assertSame(
            'Joshua 5:13-6:27',
            $this->cleaner->readingReference('The walls came down', 'Bible Reading: Joshua 5:13-6:27'),
        );
    }
}
