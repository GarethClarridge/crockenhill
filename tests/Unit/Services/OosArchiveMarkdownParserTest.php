<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\OosArchiveEntry;
use App\Services\Email\OosArchiveMarkdownParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OosArchiveMarkdownParserTest extends TestCase
{
    private OosArchiveMarkdownParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new OosArchiveMarkdownParser;
    }

    /**
     * The real 102-entry archive is deliberately uncommitted (private email content in
     * storage/scratch), so the real-corpus assertions only run where it exists.
     */
    private function realArchiveMarkdown(): string
    {
        $path = dirname(__DIR__, 3).'/storage/scratch/crockenhill_orders_of_service_archive.md';

        if (! is_file($path)) {
            $this->markTestSkipped('Real OoS archive is local-only scratch data and is not present.');
        }

        return (string) file_get_contents($path);
    }

    #[Test]
    public function it_splits_the_real_archive_into_truthful_cohorts_and_excludes_known_gaps(): void
    {
        $markdown = $this->realArchiveMarkdown();

        $entries = $this->parser->parse($markdown);

        $this->assertCount(102, $entries);
        $this->assertCount(56, array_filter($entries, fn ($entry): bool => $entry->labelQuality === 'full'));
        $this->assertCount(46, array_filter($entries, fn ($entry): bool => $entry->labelQuality === 'unverified'));
        $this->assertNotEmpty($this->parser->knownGaps($markdown));
        $this->assertFalse(in_array('Known gaps and attachment-only items', array_column($entries, 'heading'), true));
    }

    #[Test]
    public function it_applies_corrections_flags_and_ground_truth_dates_defensively(): void
    {
        $markdown = $this->realArchiveMarkdown();

        $entries = $this->parser->parse($markdown);
        $curated = $entries[0];
        $annotated = $this->entryWithHeading($entries, 'Sunday 15 March 2026 [email title likely intended 15 February]');
        $christmasWeekend = $this->entryWithHeading($entries, 'Christmas weekend 2023');
        $easter = $this->entryWithHeading($entries, 'Easter Sunday 2026');

        $this->assertSame('2026-06-05', $curated->headingDate);
        $this->assertSame('2026-07-05', $curated->correctedDate);
        $this->assertSame('2026-07-05', $curated->groundTruthDate);
        $this->assertSame('2026-07-03T09:00:00+01:00', $curated->syntheticReceivedAt?->toIso8601String());
        $this->assertContains('source_date_discrepancy', $curated->flags);
        $this->assertNotContains('weekday_mismatch', $curated->flags);

        $this->assertSame('2026-02-15', $annotated->correctedDate);
        $this->assertContains('date_discrepancy', $annotated->flags);
        $this->assertSame('2023-12-24', $christmasWeekend->groundTruthDate);
        $this->assertContains('multi_date', $christmasWeekend->flags);
        $this->assertSame('2026-04-05', $easter->groundTruthDate);
    }

    #[Test]
    public function it_reconstructs_body_and_verified_service_item_lines_without_heading_leakage(): void
    {
        $markdown = <<<'MARKDOWN'
### Sunday 12 July 2026

**Source subject:** Details for Sunday

#### Notices

- Bring lunch

#### Sunday Morning

**Welcome**

Song one

#### Sunday Evening (communion)

Prayer

Song two

---

## Known gaps and attachment-only items

- Missing attachment
MARKDOWN;

        $entry = $this->parser->parse($markdown)[0];

        $this->assertSame('Details for Sunday', $entry->subject);
        $this->assertSame(['morning', 'evening'], $entry->servicesPresent);
        $this->assertSame(['morning' => 2, 'evening' => 2], $entry->itemLineCounts);
        $this->assertSame(['Welcome', 'Song one'], $entry->itemLines['morning']);
        $this->assertStringContainsString('Sunday Morning', $entry->bodyPlain);
        $this->assertStringNotContainsString('####', $entry->bodyPlain);
        $this->assertStringNotContainsString('**', $entry->bodyPlain);
        $this->assertStringNotContainsString('Source subject', $entry->bodyPlain);
        $this->assertStringNotContainsString('Known gaps', $entry->bodyPlain);
    }

    #[Test]
    public function it_marks_headingless_entries_unverified_and_builds_deterministic_identity(): void
    {
        $markdown = <<<'MARKDOWN'
### Sunday 12 July 2026 AM

**Source subject:** Morning order

Welcome

Notices: Evening service is at 6pm
MARKDOWN;

        $first = $this->parser->parse($markdown)[0];
        $second = $this->parser->parse($markdown)[0];

        $this->assertSame('unverified', $first->labelQuality);
        $this->assertSame([], $first->servicesPresent);
        $this->assertContains('morning_only', $first->flags);
        $this->assertSame($first->syntheticMessageId, $second->syntheticMessageId);
        $this->assertSame($first->inputHash, $second->inputHash);
        $this->assertMatchesRegularExpression('/^<oos-archive-[a-f0-9]{12}@crockenhill\.local>$/', $first->syntheticMessageId);
    }

    /**
     * @param  list<OosArchiveEntry>  $entries
     */
    private function entryWithHeading(array $entries, string $heading): OosArchiveEntry
    {
        foreach ($entries as $entry) {
            if ($entry->heading === $heading) {
                return $entry;
            }
        }

        self::fail("Archive entry not found: {$heading}");
    }
}
