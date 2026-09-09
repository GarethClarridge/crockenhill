<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\FlagIncompleteSermonEvidence;
use App\Actions\FlagSuspectTranscriptRepetition;
use App\Data\SuspectTranscriptBlock;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FlagSuspectTranscriptRepetitionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_holds_a_sermon_whose_span_contains_a_loop(): void
    {
        [$log, $section] = $this->historicRun();

        $outcome = app(FlagSuspectTranscriptRepetition::class)($log, [$this->block(100.0, 148.0)]);

        self::assertSame(['raised' => 1, 'withdrawn' => 0], $outcome);
        $section->refresh();
        $metadata = $section->metadata?->toArray() ?? [];
        self::assertContains(FlagSuspectTranscriptRepetition::FLAG, $metadata['review_flags'] ?? []);
        // Through JSON a rounded 48.0 returns as the integer 48, which is the
        // same fact the action compares field by field rather than by array.
        self::assertSame(48.0, (float) $metadata['transcript_repetition_seconds']);
        self::assertCount(1, $metadata['transcript_repetition_blocks']);
        self::assertTrue($section->needs_manual_review);
    }

    #[Test]
    public function it_holds_a_sermon_that_has_no_blind_seconds_at_all(): void
    {
        // The case the 2026-09-09 review found 88 times: a looping decode leaves
        // no unobservable window behind, so the coverage flag stays silent and
        // the sermon reaches a reviewer looking complete.
        [$log, $section] = $this->historicRun();

        app(FlagSuspectTranscriptRepetition::class)($log, [$this->block(100.0, 148.0)]);

        $section->refresh();
        $flags = $section->metadata?->toArray()['review_flags'] ?? [];
        self::assertNotContains(FlagIncompleteSermonEvidence::FLAG, $flags);
        self::assertContains(FlagSuspectTranscriptRepetition::FLAG, $flags);
    }

    #[Test]
    public function it_withdraws_the_hold_once_the_loop_is_gone(): void
    {
        [$log, $section] = $this->historicRun([FlagSuspectTranscriptRepetition::FLAG]);
        $section->forceFill(['needs_manual_review' => true])->save();

        $outcome = app(FlagSuspectTranscriptRepetition::class)($log, []);

        self::assertSame(['raised' => 0, 'withdrawn' => 1], $outcome);
        $section->refresh();
        $metadata = $section->metadata?->toArray() ?? [];
        self::assertNotContains(FlagSuspectTranscriptRepetition::FLAG, $metadata['review_flags'] ?? []);
        self::assertArrayNotHasKey('transcript_repetition_seconds', $metadata);
        self::assertArrayNotHasKey('transcript_repetition_blocks', $metadata);
        self::assertFalse($section->needs_manual_review);
    }

    #[Test]
    public function it_keeps_every_other_review_flag(): void
    {
        [$log, $section] = $this->historicRun([FlagIncompleteSermonEvidence::FLAG]);

        app(FlagSuspectTranscriptRepetition::class)($log, []);

        $section->refresh();
        self::assertSame([FlagIncompleteSermonEvidence::FLAG], $section->metadata?->toArray()['review_flags'] ?? []);
    }

    #[Test]
    public function it_reports_no_change_when_the_hold_is_already_recorded(): void
    {
        [$log] = $this->historicRun();
        $flagger = app(FlagSuspectTranscriptRepetition::class);

        self::assertSame(['raised' => 1, 'withdrawn' => 0], $flagger($log, [$this->block(100.0, 148.0)]));

        // The stored seconds have been through JSON, so a rounded 48.0 returns
        // as the integer 48; a strict array comparison would report a change on
        // every pass while writing nothing new.
        self::assertSame(['raised' => 0, 'withdrawn' => 0], $flagger($log, [$this->block(100.0, 148.0)]));
    }

    #[Test]
    public function it_does_nothing_for_a_run_with_no_sermon_section(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->completed()->create();

        self::assertSame(['raised' => 0, 'withdrawn' => 0], app(FlagSuspectTranscriptRepetition::class)($log, [$this->block(100.0, 148.0)]));
    }

    #[Test]
    public function it_holds_a_childrens_talk_that_loops(): void
    {
        // Section 4368, the George Washington Carver talk the 2026-09-09 review
        // sampled: its text loops and it carried no flag at all. The sermons
        // table is polymorphic, so a rule that only knew about sermon sections
        // would hold the preaching and publish the talk.
        $log = MediaProcessingLog::factory()->livestream()->completed()->create();

        $talk = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::ChildrensTalk,
            'start_time' => 367.0,
            'end_time' => 762.99,
            'needs_manual_review' => false,
            'metadata' => ['review_flags' => []],
        ]);

        $outcome = app(FlagSuspectTranscriptRepetition::class)($log, [$this->block(400.0, 448.0)]);

        self::assertSame(['raised' => 1, 'withdrawn' => 0], $outcome);
        $talk->refresh();
        self::assertContains(FlagSuspectTranscriptRepetition::FLAG, $talk->metadata?->toArray()['review_flags'] ?? []);
        self::assertTrue($talk->needs_manual_review);
    }

    #[Test]
    public function it_holds_and_withdraws_across_sections_in_the_same_pass(): void
    {
        // One run can do both, which is why the outcome is counted two ways: a
        // single number would report a withdrawal as though a talk had just
        // been held.
        [$log, $sermon] = $this->historicRun();

        $talk = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::ChildrensTalk,
            'start_time' => 2000.0,
            'end_time' => 2400.0,
            'needs_manual_review' => true,
            'metadata' => ['review_flags' => [FlagSuspectTranscriptRepetition::FLAG]],
        ]);

        $outcome = app(FlagSuspectTranscriptRepetition::class)($log, [$this->block(100.0, 148.0)]);

        self::assertSame(['raised' => 1, 'withdrawn' => 1], $outcome);
        self::assertContains(FlagSuspectTranscriptRepetition::FLAG, $sermon->refresh()->metadata?->toArray()['review_flags'] ?? []);
        self::assertNotContains(FlagSuspectTranscriptRepetition::FLAG, $talk->refresh()->metadata?->toArray()['review_flags'] ?? []);
    }

    #[Test]
    public function it_measures_a_sermon_against_its_delivered_spans_not_its_outer_bounds(): void
    {
        // A concatenated cut joins the preached reading to the sermon and drops
        // the hymn between them. A loop inside that hymn never reaches the
        // listener, so it must not hold the sermon.
        [$log, $section] = $this->historicRun();
        $log->writeProcessingMetadata(static function (array $metadata): array {
            $metadata['sermon_extraction_plan'] = [
                'source' => 'service_sections',
                'mode' => 'concat_spans',
                'segments' => [
                    ['start_time' => 0.0, 'end_time' => 90.0],
                    ['start_time' => 400.0, 'end_time' => 1000.0],
                ],
            ];

            return $metadata;
        });

        $outcome = app(FlagSuspectTranscriptRepetition::class)($log->refresh(), [$this->block(150.0, 300.0)]);

        self::assertSame(['raised' => 0, 'withdrawn' => 0], $outcome);
        self::assertNotContains(FlagSuspectTranscriptRepetition::FLAG, $section->refresh()->metadata?->toArray()['review_flags'] ?? []);
    }

    private function block(float $start, float $end): SuspectTranscriptBlock
    {
        return new SuspectTranscriptBlock(
            start: $start,
            end: $end,
            reason: SuspectTranscriptBlock::REASON_REPEATED_PHRASE,
            words: 84,
            wordsPerMinute: 105.0,
            phrase: 'and will bring the sorrow on me',
            repeats: 12,
        );
    }

    /**
     * @param  list<string>  $flags
     * @return array{0: MediaProcessingLog, 1: ServiceSection}
     */
    private function historicRun(array $flags = []): array
    {
        $log = MediaProcessingLog::factory()->livestream()->completed()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon,
            'start_time' => 0.0,
            'end_time' => 1000.0,
            'needs_manual_review' => false,
            'metadata' => ['review_flags' => $flags],
        ]);

        return [$log, $section];
    }
}
