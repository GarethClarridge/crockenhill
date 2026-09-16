<?php

declare(strict_types=1);

namespace Tests\Integration\Services\SectionPublication;

use App\Data\SuspectTranscriptBlock;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\SectionPublication\SongLoopedTranscript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A song section whose transcript is largely a decode loop cannot evidence its own identity.
 *
 * The §4.1b song-loop census (2026-09-14) found 229 song sections carrying repetition blocks
 * and 65 at half loop or more, and the P8-Q14 screen that produced those blocks had no song-side
 * consumer at all. Re-anchored to current membership on 2026-09-16 that is 226 and 63, of which
 * 25 were unheld with 22 carrying a generated clip, every one bound `confirmed`.
 */
class SongLoopedTranscriptTest extends TestCase
{
    use RefreshDatabase;

    private SongLoopedTranscript $loopedTranscript;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loopedTranscript = app(SongLoopedTranscript::class);
    }

    /**
     * The demotion itself. Half the section's seconds covered by blocks is the line the plan
     * set, and §1862's "for the lord i will st" repeating 96 times is what it is drawn against.
     */
    #[Test]
    public function it_raises_a_risk_when_half_the_section_or_more_is_loop(): void
    {
        $section = $this->songSection(blocks: [
            $this->block(start: 100.0, end: 220.0, phrase: 'for the lord i will stand', repeats: 96),
        ]);

        $observations = $this->loopedTranscript->observe($section);

        $this->assertCount(1, $observations);
        $this->assertTrue($observations[0]['risk']);
        $this->assertSame(0.6, $observations[0]['looped_share']);
        $this->assertSame(1, $observations[0]['blocks']);
    }

    /**
     * Below the line the observation is still recorded. 85 sections sit between a fifth and a
     * half on current membership, and a reviewer opening one should see the measurement rather
     * than an absence.
     */
    #[Test]
    public function it_records_a_lesser_loop_without_raising_a_risk(): void
    {
        $section = $this->songSection(blocks: [
            $this->block(start: 100.0, end: 140.0, phrase: 'sing to the song', repeats: 12),
        ]);

        $observations = $this->loopedTranscript->observe($section);

        $this->assertCount(1, $observations);
        $this->assertFalse($observations[0]['risk']);
        $this->assertSame(0.2, $observations[0]['looped_share']);
    }

    /**
     * An empty screen is the positive claim that the transcript was read and found clear, so
     * there is nothing to say about it.
     */
    #[Test]
    public function it_says_nothing_about_a_transcript_screened_clean(): void
    {
        $section = $this->songSection(blocks: []);

        $this->assertSame([], $this->loopedTranscript->observe($section));
    }

    /**
     * Null is *unknown*, not clean: every run that completed before the screen existed carries
     * no blocks, and reading that as clearance would silently pass exactly the corpus the screen
     * was built for. It is recorded, and it does not demote on its own.
     */
    #[Test]
    public function it_does_not_read_an_unscreened_run_as_clean(): void
    {
        $section = $this->songSection(blocks: null);

        $observations = $this->loopedTranscript->observe($section);

        $this->assertCount(1, $observations);
        $this->assertFalse($observations[0]['risk']);
        $this->assertSame('blocks_not_recorded', $observations[0]['status']);
    }

    /** Only the seconds inside the section count; a block running past its end is not its loop. */
    #[Test]
    public function it_counts_only_the_part_of_a_block_inside_the_section(): void
    {
        $section = $this->songSection(blocks: [
            $this->block(start: 100.0, end: 400.0, phrase: 'we re going to sing again', repeats: 22),
        ]);

        $observations = $this->loopedTranscript->observe($section);

        $this->assertSame(1.0, $observations[0]['looped_share']);
        $this->assertTrue($observations[0]['crosses_section']);
    }

    /** Overlapping blocks share seconds; counting them twice would inflate every share past 1. */
    #[Test]
    public function it_counts_seconds_shared_by_two_blocks_once(): void
    {
        $section = $this->songSection(blocks: [
            $this->block(start: 100.0, end: 200.0, phrase: 'first loop', repeats: 9),
            $this->block(start: 150.0, end: 220.0, phrase: 'second loop', repeats: 7),
        ]);

        $observations = $this->loopedTranscript->observe($section);

        $this->assertSame(0.6, $observations[0]['looped_share']);
        $this->assertSame(2, $observations[0]['blocks']);
    }

    /**
     * Banked evidence fingerprints what it read, so a re-screened transcript makes it stale
     * rather than leaving a cleared clip cleared.
     */
    #[Test]
    public function it_fingerprints_the_blocks_it_read(): void
    {
        $section = $this->songSection(blocks: [$this->block(start: 100.0, end: 220.0)]);
        $before = $this->loopedTranscript->inputs($section);

        $section->processingLog->putServiceTranscriptPath(
            'service-transcripts/looped.normalized.json',
            [],
            [$this->block(start: 100.0, end: 130.0)],
        );

        $this->assertNotSame($before, $this->loopedTranscript->inputs($section->fresh()));
    }

    /**
     * @param  list<array<string, mixed>>|null  $blocks
     */
    private function songSection(?array $blocks): ServiceSection
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $processingLog->putServiceTranscriptPath('service-transcripts/looped.normalized.json', [], $blocks);

        return ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 1,
            'start_time' => 100.0,
            'end_time' => 300.0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function block(float $start, float $end, string $phrase = 'looping phrase', int $repeats = 10): array
    {
        return new SuspectTranscriptBlock(
            start: $start,
            end: $end,
            reason: SuspectTranscriptBlock::REASON_REPEATED_PHRASE,
            words: $repeats * 4,
            wordsPerMinute: 120.0,
            phrase: $phrase,
            repeats: $repeats,
        )->toArray();
    }
}
