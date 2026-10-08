<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\ChurchService\Structure\SilenceSnapService;
use App\Services\Media\Audio\AudioTimeline;
use App\Services\Media\Audio\RmsAnalysisService;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AudioTimelineFixture;
use Tests\TestCase;

class SilenceSnapServiceTest extends TestCase
{
    private SilenceSnapService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('media-processing.segmentation.rms_threshold', -45.0);
        Config::set('media-processing.service_structure.snap_window_seconds', 30);

        $this->service = new SilenceSnapService(new RmsAnalysisService);
    }

    #[Test]
    public function it_snaps_boundaries_to_the_nearest_silence_within_the_window(): void
    {
        // Silences at 118 s and 425 s; loud everywhere else.
        $rmsLog = $this->rmsLog([
            [0.0, -20.0],
            [60.0, -25.0],
            [118.0, -60.0],
            [200.0, -22.0],
            [300.0, -24.0],
            [425.0, -70.0],
            [500.0, -21.0],
        ]);

        $structure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0),
            $this->section('song', 120.0, 430.0),
        ]);

        $snapped = $this->service->snap($structure, $rmsLog);

        // The shared 120 s boundary snaps to the silence at 118 s on both sides.
        $this->assertSame(118.0, $snapped->sections[0]->endTime);
        $this->assertSame(118.0, $snapped->sections[1]->startTime);
        // The song's end snaps to the silence at 425 s.
        $this->assertSame(425.0, $snapped->sections[1]->endTime);
        // Machine-readable deltas ride along for metadata.
        $this->assertSame(['start' => 0.0, 'end' => -2.0], $snapped->sections[0]->snapDeltas);
        $this->assertSame(['start' => -2.0, 'end' => -5.0], $snapped->sections[1]->snapDeltas);
    }

    #[Test]
    public function it_leaves_boundaries_unsnapped_when_no_silence_is_in_range(): void
    {
        // The only silence is 200 s away from every boundary.
        $rmsLog = $this->rmsLog([
            [0.0, -20.0],
            [300.0, -60.0],
            [600.0, -20.0],
        ]);

        $structure = ServiceStructure::fromSections([
            $this->section('sermon', 30.0, 90.0),
        ]);

        $snapped = $this->service->snap($structure, $rmsLog);

        $this->assertSame(30.0, $snapped->sections[0]->startTime);
        $this->assertSame(90.0, $snapped->sections[0]->endTime);
        $this->assertSame(['start' => 0.0, 'end' => 0.0], $snapped->sections[0]->snapDeltas);
        $this->assertStringContainsString('left unsnapped', implode(' ', $snapped->sections[0]->notes));
    }

    #[Test]
    public function it_reconciles_a_one_second_boundary_rounding_overlap(): void
    {
        $rmsLog = $this->rmsLog([
            [0.0, -20.0],
            [2400.0, -20.0],
        ]);
        $structure = ServiceStructure::fromSections([
            $this->section('bible_reading', 1200.0, 1832.0),
            $this->section('prayer', 1831.0, 1900.0),
        ]);

        $snapped = $this->service->snap($structure, $rmsLog);

        $this->assertSame(1831.5, $snapped->sections[0]->endTime);
        $this->assertSame(1831.5, $snapped->sections[1]->startTime);
        $this->assertSame(['start' => 0.0, 'end' => -0.5], $snapped->sections[0]->snapDeltas);
        $this->assertSame(['start' => 0.5, 'end' => 0.0], $snapped->sections[1]->snapDeltas);
    }

    /**
     * The real 2024-07-28 shape: a preacher hands off to a reader mid-sermon and
     * then resumes. That is one sermon, and the merge is what lets the validator's
     * "at most one sermon" rule stay true without losing the conclusion.
     */
    #[Test]
    public function it_merges_sermon_fragments_split_by_a_mid_sermon_reading(): void
    {
        $rmsLog = $this->rmsLog([[0.0, -20.0], [4000.0, -20.0]]);
        $structure = ServiceStructure::fromSections([
            $this->section('song', 1500.0, 1890.0),
            $this->section('sermon', 1896.0, 2679.9),
            $this->section('bible_reading', 2682.0, 2895.0),
            $this->section('sermon', 2912.0, 3055.0),
            $this->section('prayer', 3119.2, 3142.0),
        ]);

        $snapped = $this->service->snap($structure, $rmsLog);

        $sermons = array_values(array_filter(
            $snapped->sections,
            static fn ($section): bool => $section->type->value === 'sermon',
        ));

        $this->assertCount(1, $sermons);
        $this->assertSame(1896.0, $sermons[0]->startTime);
        $this->assertSame(3055.0, $sermons[0]->endTime);
        $this->assertContains(
            ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED,
            $sermons[0]->reviewFlags,
        );
        $this->assertSame(
            ['song', 'sermon', 'prayer'],
            array_map(static fn ($section): string => $section->type->value, $snapped->sections),
        );
    }

    /**
     * Each item the merge absorbs is recorded with its own span, so an answer about one reading
     * can settle that reading and not another (canary 12 review, 2026-10-06).
     */
    #[Test]
    public function the_merge_records_each_interruption_it_absorbs(): void
    {
        $rmsLog = $this->rmsLog([[0.0, -20.0], [4000.0, -20.0]]);
        $structure = ServiceStructure::fromSections([
            $this->section('sermon', 1000.0, 1400.0),
            $this->section('bible_reading', 1405.0, 1500.0),
            $this->section('sermon', 1505.0, 1800.0),
            $this->section('bible_reading', 1805.0, 1900.0),
            $this->section('sermon', 1905.0, 2400.0),
        ]);

        $merged = $this->service->snap($structure, $rmsLog)->sections[0];

        $this->assertSame(
            [['bible_reading', 1405.0, 1500.0], ['bible_reading', 1805.0, 1900.0]],
            SilenceSnapService::mergedInterruptions($merged->notes),
        );
    }

    /**
     * Run 949 (operator, 2026-10-07: "if the first reading is included then the second must be").
     * The preacher reads both passages himself, inside his sermon: he names each just before it
     * ("I want to start with Hebrews chapter 9", "one that stands out to me from Numbers 21") and
     * carries straight on after it. Neither is the passage he preaches, but his own speech calls
     * for both, so each is part of the sermon and nothing is left to ask.
     */
    #[Test]
    public function readings_the_sermon_itself_introduces_are_settled_as_part_of_it(): void
    {
        $merged = $this->service->snap($this->embeddedReadings(), $this->rmsLog([[0.0, -20.0], [5000.0, -20.0]]), $this->embeddedReadingsTranscript())->sections[0];

        $this->assertSame('sermon', $merged->type->value);
        $this->assertNotContains(ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED, $merged->reviewFlags);
        $this->assertCount(2, SilenceSnapService::mergedInterruptions($merged->notes));
        foreach (SilenceSnapService::mergedInterruptions($merged->notes) as $occurrence) {
            $this->assertTrue(SilenceSnapService::isSettled($occurrence, $merged->notes));
        }
    }

    /** Only the readings the evidence ties to the sermon are settled: an unnamed one is still asked. */
    #[Test]
    public function a_reading_the_sermon_does_not_introduce_keeps_the_merge_in_question(): void
    {
        $cues = array_values(array_filter($this->embeddedReadingsTranscript()->cues, static fn (array $cue): bool => ! str_contains($cue['text'], 'Numbers 21')));
        $merged = $this->service->snap($this->embeddedReadings(), $this->rmsLog([[0.0, -20.0], [5000.0, -20.0]]),
            ChurchServiceTranscript::fromCues($cues, 5000, ChurchServiceTranscript::SOURCE_MOCK))->sections[0];

        $this->assertContains(ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED, $merged->reviewFlags);
        [$hebrews, $numbers] = SilenceSnapService::mergedInterruptions($merged->notes);
        $this->assertTrue(SilenceSnapService::isSettled($hebrews, $merged->notes));
        $this->assertFalse(SilenceSnapService::isSettled($numbers, $merged->notes));
    }

    /**
     * Run 1250's re-read (one draw): "I do want to read verses 24 to 31 to you", Job 30:24-31 inside
     * a sermon on Job 29-31. The passage is the one preached, so it is the sermon's own reading.
     */
    #[Test]
    public function a_reading_within_the_preached_passage_is_settled_as_part_of_the_sermon(): void
    {
        $structure = ServiceStructure::fromSections([
            $this->section('sermon', 2454.0, 3070.0, sermon: 'Job 29-31'),
            $this->section('bible_reading', 3070.0, 3122.0, reading: 'Job 30:24-31'),
            $this->section('sermon', 3124.0, 4311.0, sermon: 'Job 29-31'),
        ]);

        $merged = $this->service->snap($structure, $this->rmsLog([[0.0, -20.0], [5000.0, -20.0]]))->sections[0];

        $this->assertNotContains(ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED, $merged->reviewFlags);
    }

    /**
     * What stays a question: a prayer between two sermon parts, a reading with no reference, and
     * a passage named only before the sermon began (the leader's, not the preacher's).
     */
    #[Test]
    public function interruptions_without_evidence_tying_them_to_the_sermon_stay_in_question(): void
    {
        $rms = $this->rmsLog([[0.0, -20.0], [5000.0, -20.0]]);
        $prayer = $this->service->snap(ServiceStructure::fromSections([
            $this->section('sermon', 1000.0, 1800.0, sermon: 'John 19'),
            $this->section('prayer', 1805.0, 1900.0),
            $this->section('sermon', 1905.0, 2400.0, sermon: 'John 19'),
        ]), $rms)->sections[0];
        $unreferenced = $this->service->snap(ServiceStructure::fromSections([
            $this->section('sermon', 1000.0, 1800.0, sermon: 'John 19'),
            $this->section('bible_reading', 1805.0, 1900.0),
            $this->section('sermon', 1905.0, 2400.0, sermon: 'John 19'),
        ]), $rms, ChurchServiceTranscript::fromCues([['start' => 1790.0, 'end' => 1804.0, 'text' => 'Hebrews chapter 9 says,']], 5000, ChurchServiceTranscript::SOURCE_MOCK))->sections[0];
        $namedBefore = $this->service->snap(ServiceStructure::fromSections([
            $this->section('bible_reading', 900.0, 990.0, reading: 'John 19:1-16'),
            $this->section('sermon', 1000.0, 1010.0, sermon: 'John 19'),
            $this->section('bible_reading', 1015.0, 1100.0, reading: 'Hebrews 9:11-14'),
            $this->section('sermon', 1105.0, 2400.0, sermon: 'John 19'),
        ]), $rms, ChurchServiceTranscript::fromCues([['start' => 980.0, 'end' => 985.0, 'text' => 'Later we will hear Hebrews 9.']], 5000, ChurchServiceTranscript::SOURCE_MOCK))->sections[1];

        foreach ([$prayer, $unreferenced, $namedBefore] as $merged) {
            $this->assertContains(ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED, $merged->reviewFlags);
        }
    }

    /**
     * Codex review, 2026-10-07: the passage must be named where the reading is introduced, before
     * it or as it opens. A line minutes later naming it says nothing about who called for it, and
     * no line at the reading's opening leaves the question open.
     */
    #[Test]
    public function an_unrelated_later_reference_does_not_settle_an_earlier_reading(): void
    {
        $structure = ServiceStructure::fromSections([
            $this->section('sermon', 100, 150, sermon: 'John 19'),
            $this->section('bible_reading', 150, 200, reading: 'Hebrews 9:11-14'),
            $this->section('sermon', 200, 400, sermon: 'John 19'),
        ]);
        $later = ChurchServiceTranscript::fromCues([['start' => 350.0, 'end' => 355.0, 'text' => 'Later, compare Hebrews 9 with this passage.']], 500, ChurchServiceTranscript::SOURCE_MOCK);
        $insideAfterItsOpening = ChurchServiceTranscript::fromCues([
            ['start' => 150.0, 'end' => 153.0, 'text' => 'But when Christ appeared as a high priest'],
            ['start' => 180.0, 'end' => 183.0, 'text' => 'as Hebrews 9 says'],
        ], 500, ChurchServiceTranscript::SOURCE_MOCK);

        foreach ([$later, $insideAfterItsOpening] as $transcript) {
            $merged = $this->service->snap($structure, '', $transcript)->sections[0];
            $this->assertContains(ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED, $merged->reviewFlags);
        }
    }

    /**
     * Codex review, 2026-10-07: a reading between two sermon parts that preach different passages
     * does not show one sermon resumed. Continuity needs every part to name the same passage; a
     * conflicting or missing part keeps the merge in review.
     */
    #[Test]
    public function sermon_parts_that_do_not_name_one_passage_keep_a_reading_merge_in_review(): void
    {
        foreach (['Romans 8', null] as $resumed) {
            $structure = ServiceStructure::fromSections([
                $this->section('sermon', 100, 150, sermon: 'John 19'),
                $this->section('bible_reading', 150, 200, reading: 'John 19:15-30'),
                $this->section('sermon', 200, 400, sermon: $resumed),
            ]);

            $merged = $this->service->snap($structure, '')->sections[0];

            $this->assertContains(ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED, $merged->reviewFlags, 'resumed as '.($resumed ?? 'no reference'));
        }
    }

    #[Test]
    public function it_merges_sermon_fragments_split_by_a_mid_sermon_prayer(): void
    {
        $rmsLog = $this->rmsLog([[0.0, -20.0], [4000.0, -20.0]]);
        $structure = ServiceStructure::fromSections([
            $this->section('sermon', 1000.0, 1800.0),
            $this->section('prayer', 1805.0, 1900.0),
            $this->section('sermon', 1905.0, 2400.0),
        ]);

        $snapped = $this->service->snap($structure, $rmsLog);

        $this->assertCount(1, $snapped->sections);
        $this->assertSame(1000.0, $snapped->sections[0]->startTime);
        $this->assertSame(2400.0, $snapped->sections[0]->endTime);
    }

    /**
     * Two sermons separated by a song are two talks, not one interrupted sermon.
     * Merging them would publish the wrong span, so this must keep failing
     * validation.
     */
    #[Test]
    public function it_leaves_two_sermons_separated_by_a_song_alone(): void
    {
        $rmsLog = $this->rmsLog([[0.0, -20.0], [4000.0, -20.0]]);
        $structure = ServiceStructure::fromSections([
            $this->section('sermon', 1000.0, 1800.0),
            $this->section('song', 1805.0, 2000.0),
            $this->section('sermon', 2005.0, 2400.0),
        ]);

        $snapped = $this->service->snap($structure, $rmsLog);

        $this->assertCount(3, $snapped->sections);
    }

    /**
     * A merge that would exceed the sermon ceiling is more likely two talks than
     * one interrupted sermon, so it is left for validation to reject.
     */
    #[Test]
    public function it_refuses_a_merge_that_would_exceed_the_sermon_ceiling(): void
    {
        config(['media-processing.section_extraction.enhanced_sermon.max_sermon_duration_seconds' => 600]);

        $rmsLog = $this->rmsLog([[0.0, -20.0], [4000.0, -20.0]]);
        $structure = ServiceStructure::fromSections([
            $this->section('sermon', 1000.0, 1400.0),
            $this->section('bible_reading', 1405.0, 1500.0),
            $this->section('sermon', 1505.0, 2400.0),
        ]);

        $snapped = $this->service->snap($structure, $rmsLog);

        $this->assertCount(3, $snapped->sections);
    }

    #[Test]
    public function it_does_not_hide_a_material_section_overlap(): void
    {
        $rmsLog = $this->rmsLog([
            [0.0, -20.0],
            [2400.0, -20.0],
        ]);
        $structure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0),
            $this->section('song', 100.0, 400.0),
        ]);

        $snapped = $this->service->snap($structure, $rmsLog);

        $this->assertSame(120.0, $snapped->sections[0]->endTime);
        $this->assertSame(100.0, $snapped->sections[1]->startTime);
    }

    #[Test]
    public function shared_boundaries_snap_together_and_stay_chronological(): void
    {
        // Silences at 95 s and 155 s. Each shared boundary must move to the
        // same silence on both sides, keeping the sections contiguous.
        $rmsLog = $this->rmsLog([
            [0.0, -20.0],
            [95.0, -60.0],
            [155.0, -60.0],
            [210.0, -20.0],
        ]);

        $structure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0),   // midpoint 60
            $this->section('song', 120.0, 180.0),    // midpoint 150
            $this->section('prayer', 180.0, 240.0),  // midpoint 210
        ]);

        $snapped = $this->service->snap($structure, $rmsLog);

        // Boundary 120 (welcome end / song start): the silence at 95 s is the
        // only one in the 30 s window and lies between midpoints 60 and 150.
        $this->assertSame(95.0, $snapped->sections[0]->endTime);
        $this->assertSame(95.0, $snapped->sections[1]->startTime);

        // Boundary 180 (song end / prayer start): nearest silence 155 s is
        // within the window and between midpoints 150 and 210 — legal.
        $this->assertSame(155.0, $snapped->sections[1]->endTime);
        $this->assertSame(155.0, $snapped->sections[2]->startTime);

        // Sections remain chronological and non-overlapping after snapping.
        $previousEnd = 0.0;
        foreach ($snapped->sections as $section) {
            $this->assertGreaterThan($section->startTime, $section->endTime);
            $this->assertGreaterThanOrEqual($previousEnd, $section->startTime);
            $previousEnd = $section->endTime;
        }
    }

    #[Test]
    public function a_silence_beyond_a_neighbours_midpoint_is_rejected(): void
    {
        // The only silence near the 100 s boundary is at 130 s — beyond the
        // second section's midpoint (125 s), so the boundary must stay put.
        $rmsLog = $this->rmsLog([
            [0.0, -20.0],
            [130.0, -60.0],
            [200.0, -20.0],
        ]);

        $structure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 100.0),
            $this->section('song', 100.0, 150.0), // midpoint 125
        ]);

        $snapped = $this->service->snap($structure, $rmsLog);

        $this->assertSame(100.0, $snapped->sections[0]->endTime);
        $this->assertSame(100.0, $snapped->sections[1]->startTime);
    }

    #[Test]
    public function it_uses_the_calibrated_adaptive_threshold_when_the_log_has_enough_samples(): void
    {
        // 1,000+ samples engage the same adaptive thresholding the
        // segmentation pipeline uses. The bottom 30% of this log sits at
        // -55 dB, so the calibrated threshold is -55 — under the fixed -45
        // threshold the -48 dB dip at 100 s would wrongly count as silence.
        $samples = [];

        for ($time = 0; $time < 1050; $time++) {
            $samples[] = [(float) $time, match (true) {
                $time === 100 => -48.0,
                $time >= 700 => -55.0,
                default => -25.0,
            }];
        }

        $structure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 110.0),
            $this->section('song', 110.0, 220.0),
        ]);

        $snapped = $this->service->snap($structure, $this->rmsLog($samples));

        // The -48 dB dip is not silence under the calibrated threshold and no
        // true silence is within the window, so the boundary stays put.
        $this->assertSame(110.0, $snapped->sections[0]->endTime);
        $this->assertSame(110.0, $snapped->sections[1]->startTime);
        $this->assertStringContainsString('left unsnapped', implode(' ', $snapped->sections[0]->notes));
    }

    #[Test]
    public function an_empty_or_silence_free_log_leaves_the_structure_untouched(): void
    {
        $structure = ServiceStructure::fromSections([
            $this->section('sermon', 100.0, 2000.0),
        ]);

        $snapped = $this->service->snap($structure, $this->rmsLog([[0.0, -20.0], [500.0, -25.0]]));

        $this->assertSame($structure->toArray(), $snapped->toArray());
    }

    #[Test]
    public function silence_snaps_cannot_retreat_into_runs_936_and_1117s_last_spoken_cues(): void
    {
        foreach ([[2472.96, 2532.16, 2529.0, 2531.95], [1717.78, 1800.64, 1798.0, 1800.52]] as [$start, $end, $cueStart, $silence]) {
            $transcript = ChurchServiceTranscript::fromCues([
                ['start' => $cueStart, 'end' => $end, 'text' => 'Last word'],
            ], 3000, ChurchServiceTranscript::SOURCE_MOCK);
            $structure = ServiceStructure::fromSections([$this->section('bible_reading', $start, $end)]);
            $result = $this->service->snap($structure, $this->rmsLog([[0.0, -20.0], [$silence, -60.0], [3000.0, -20.0]]), $transcript);
            $this->assertSame($end, $result->sections[0]->endTime);
        }
    }

    #[Test]
    public function silence_cannot_advance_a_start_into_speech_or_cross_the_next_cue(): void
    {
        $transcript = ChurchServiceTranscript::fromCues([
            ['start' => 100.4, 'end' => 110.8, 'text' => 'This item'],
            ['start' => 112.2, 'end' => 120.5, 'text' => 'Next item'],
        ], 3000, ChurchServiceTranscript::SOURCE_MOCK);
        $structure = ServiceStructure::fromSections([$this->section('prayer', 100.4, 110.8)]);
        $result = $this->service->snap($structure, $this->rmsLog([[0.0, -20.0], [100.8, -60.0], [113.0, -60.0], [3000.0, -20.0]]), $transcript);
        $this->assertSame([100.4, 110.8], [$result->sections[0]->startTime, $result->sections[0]->endTime]);
    }

    /**
     * Run 1311 in the canary 13 draws: a baptism drawn to 1520 snapped on to 1544.08, the end of a
     * 30 s "Thank you." whisper wrote over the song that follows. A line too long for its words in
     * music is not speech the snap must keep whole; the baptism ends with its last spoken line.
     */
    #[Test]
    public function a_hallucinated_line_over_music_does_not_carry_a_section_end_with_it(): void
    {
        $transcript = ChurchServiceTranscript::fromCues([
            ['start' => 1507.54, 'end' => 1512.16, 'text' => 'I baptise you in the name of the Father and of the Son and'],
            ['start' => 1512.16, 'end' => 1514.10, 'text' => 'of the Holy Spirit.'],
            ['start' => 1514.10, 'end' => 1544.08, 'text' => 'Thank you.'],
        ], 3000, ChurchServiceTranscript::SOURCE_MOCK);
        $timeline = AudioTimeline::fromArray(AudioTimelineFixture::payload([[1475, 1515, 0.02, 0.8], [1515, 1600, 0.8, 0.02]], 3000.0));
        $structure = ServiceStructure::fromSections([$this->section('other', 1476.38, 1520.0)]);

        $result = $this->service->snap($structure, $this->rmsLog([[0.0, -20.0], [1544.2, -60.0], [1545.5, -20.0], [3000.0, -20.0]]), $transcript, $timeline);

        $this->assertLessThanOrEqual(1520.0, $result->sections[0]->endTime);
    }

    /** Run 949's merged sermon, as one draw detected it. */
    private function embeddedReadings(): ServiceStructure
    {
        return ServiceStructure::fromSections([
            $this->section('sermon', 2690.0, 2932.0, sermon: 'John 19'),
            $this->section('bible_reading', 2933.0, 2981.0, reading: 'Hebrews 9:11-14'),
            $this->section('sermon', 2981.0, 3110.0, sermon: 'John 19'),
            $this->section('bible_reading', 3111.0, 3172.0, reading: 'Numbers 21:4-9'),
            $this->section('sermon', 3173.0, 4599.0, sermon: 'John 19'),
        ]);
    }

    private function embeddedReadingsTranscript(): ChurchServiceTranscript
    {
        return ChurchServiceTranscript::fromCues([
            ['start' => 2925.5, 'end' => 2929.2, 'text' => 'And we are going to be looking at different sections from'],
            ['start' => 2929.2, 'end' => 2930.5, 'text' => 'John 19.'],
            ['start' => 2931.2, 'end' => 2934.4, 'text' => 'But as we begin, I want to start with Hebrews chapter 9,'],
            ['start' => 2934.4, 'end' => 2937.4, 'text' => 'starting with verse 11, which says,'],
            ['start' => 2979.5, 'end' => 2981.5, 'text' => 'to serve the living God.'],
            ['start' => 2981.9, 'end' => 2985.1, 'text' => 'Nearly, just over 2,000 years ago,'],
            ['start' => 3091.0, 'end' => 3095.1, 'text' => 'And yet the crucifixion of Christ fulfilled so many Old'],
            ['start' => 3098.5, 'end' => 3101.9, 'text' => "There's one that stands out to me from Numbers 21."],
            ['start' => 3102.7, 'end' => 3108.7, 'text' => 'This is, we zoom in on the people of Israel wandering in'],
            ['start' => 3108.7, 'end' => 3109.5, 'text' => 'the desert.'],
            ['start' => 3110.0, 'end' => 3111.8, 'text' => 'And Numbers 21 verse 4 says,'],
            ['start' => 3166.6, 'end' => 3171.4, 'text' => 'And if a serpent bit anyone, he would look at the bronze'],
            ['start' => 3173.8, 'end' => 3181.2, 'text' => 'John references this situation, this event in his own'],
        ], 5000, ChurchServiceTranscript::SOURCE_MOCK);
    }

    private function section(string $type, float $start, float $end, ?string $reading = null, ?string $sermon = null): ServiceStructureSection
    {
        $section = ServiceStructureSection::fromArray([
            'type' => $type,
            'start_time' => $start,
            'end_time' => $end,
            'confidence' => 0.9,
            'reading_reference' => $reading,
            'sermon_reference' => $sermon,
        ]);

        assert($section instanceof ServiceStructureSection);

        return $section;
    }

    /**
     * Build ffmpeg-astats-style RMS log content from [time, rms] pairs.
     *
     * @param  list<array{0: float, 1: float}>  $samples
     */
    private function rmsLog(array $samples): string
    {
        $lines = [];

        foreach ($samples as $index => [$time, $rms]) {
            $lines[] = sprintf('frame:%d pts:%d pts_time:%.3f', $index, (int) ($time * 8000), $time);
            $lines[] = sprintf('lavfi.astats.Overall.RMS_level=%.1f', $rms);
        }

        return implode("\n", $lines)."\n";
    }
}
