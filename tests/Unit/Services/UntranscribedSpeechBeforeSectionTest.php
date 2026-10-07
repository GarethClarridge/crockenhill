<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\ChurchService\Structure\UntranscribedSpeechBeforeSection;
use App\Services\Media\Audio\AudioTimeline;
use App\Support\SectionReviewFlagPolicy;
use App\Support\SermonAutoExtractionPolicy;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AudioTimelineFixture;
use Tests\TestCase;

/**
 * F11: the classifier hears speech after a song that the transcript holds no words for, and the
 * next section's first cue opens mid-sentence. The stretch is marked and the section is asked
 * about it; nothing moves (operator 2026-10-05: mark + ask).
 */
class UntranscribedSpeechBeforeSectionTest extends TestCase
{
    private const QUESTION = ServiceStructureValidator::FLAG_UNTRANSCRIBED_SPEECH_BEFORE_SECTION;

    /**
     * 949 (canary): "Alleluia" ends the song, 2690–2706 is speech with no cue, and the prayer's
     * first cue opens "pray. Our Heavenly Father…".
     */
    #[Test]
    public function speech_with_no_cue_between_a_song_and_a_prayer_opening_mid_sentence_is_asked_about(): void
    {
        $prayer = $this->apply(
            [$this->section('song', 2400.0, 2690.0), $this->section('prayer', 2706.4, 2800.0)],
            [
                ['start' => 2670.0, 'end' => 2684.6, 'text' => 'Alleluia, alleluia.'],
                ['start' => 2706.4, 'end' => 2711.9, 'text' => 'pray. Our Heavenly Father,'],
                ['start' => 2711.9, 'end' => 2718.0, 'text' => 'we thank you for this morning.'],
            ],
            [[2400, 2690, 0.9, 0.1], [2690, 2800, 0.05, 0.8]],
            2800.0,
        )[1];

        $this->assertContains(self::QUESTION, $prayer->reviewFlags);
        $this->assertSame([UntranscribedSpeechBeforeSection::note(2690.0, 2706.4)], $this->questionNotes($prayer));
        $this->assertSame(2706.4, $prayer->startTime, 'the section keeps its bounds');
    }

    /**
     * 1025 (canary): the speech hides under one 30 s "Thank you." filler cue running up to the
     * prayer. A cue that long is not a transcription of what was said.
     */
    #[Test]
    public function speech_hidden_under_a_long_filler_cue_is_asked_about(): void
    {
        $prayer = $this->apply(
            [$this->section('song', 400.0, 630.8), $this->section('prayer', 630.8, 700.0)],
            [
                ['start' => 590.0, 'end' => 600.8, 'text' => 'Amen.'],
                ['start' => 600.8, 'end' => 630.8, 'text' => 'Thank you.'],
                ['start' => 630.8, 'end' => 636.0, 'text' => 'the Lord be with you all.'],
            ],
            [[400, 610, 0.9, 0.1], [610, 700, 0.05, 0.8]],
            700.0,
        )[1];

        $this->assertContains(self::QUESTION, $prayer->reviewFlags);
        $this->assertSame([UntranscribedSpeechBeforeSection::note(610.0, 630.8)], $this->questionNotes($prayer));
    }

    /** A slow speaker's long line, heard as speech throughout, is a line: nothing is asked. */
    #[Test]
    public function a_slow_genuine_line_is_not_taken_for_filler(): void
    {
        $prayer = $this->apply(
            [$this->section('song', 400.0, 610.0), $this->section('prayer', 610.5, 700.0)],
            [
                ['start' => 590.0, 'end' => 600.8, 'text' => 'Amen.'],
                ['start' => 610.5, 'end' => 627.0, 'text' => 'Lord, we come to you this morning, quietly, and with thanks for all.'],
                ['start' => 627.5, 'end' => 636.0, 'text' => 'the Lord be with you all.'],
            ],
            [[400, 610, 0.9, 0.1], [610, 700, 0.05, 0.8]],
            700.0,
        )[1];

        $this->assertSame([], $this->questionNotes($prayer));
    }

    /**
     * Canary 12 (B2): a cue of punctuation alone holds no words, so it is no line, whatever its
     * length; recovery treats it the same way.
     */
    #[Test]
    public function speech_under_a_punctuation_only_cue_is_asked_about(): void
    {
        $prayer = $this->apply(
            [$this->section('song', 400.0, 610.0), $this->section('prayer', 630.8, 700.0)],
            [
                ['start' => 590.0, 'end' => 600.8, 'text' => 'Amen.'],
                ['start' => 612.0, 'end' => 624.0, 'text' => '. . . .'],
                ['start' => 630.8, 'end' => 636.0, 'text' => 'the Lord be with you all.'],
            ],
            [[400, 610, 0.9, 0.1], [610, 700, 0.05, 0.8]],
            700.0,
        )[1];

        $this->assertSame([UntranscribedSpeechBeforeSection::note(610.0, 630.8)], $this->questionNotes($prayer));
    }

    /**
     * 993 (I5): the sermon's first cue is a fragment ("the last") ending where the section's first
     * full cue starts. The two are one utterance, so the stretch ends before the fragment.
     */
    #[Test]
    public function a_cue_running_into_the_first_one_is_part_of_the_same_opening(): void
    {
        $sermon = $this->apply(
            [$this->section('song', 1967.0, 2117.3), $this->section('sermon', 2117.3, 3000.0)],
            [
                ['start' => 2117.1, 'end' => 2117.2, 'text' => 'the last'],
                ['start' => 2117.2, 'end' => 2118.0, 'text' => 'part of'],
                ['start' => 2118.0, 'end' => 2120.3, 'text' => 'Joshua chapter 5.'],
            ],
            [[1967, 2105, 0.9, 0.1], [2105, 3000, 0.05, 0.9]],
            3000.0,
        )[1];

        $this->assertSame([UntranscribedSpeechBeforeSection::note(2105.0, 2117.1)], $this->questionNotes($sermon));
    }

    /**
     * 1138 and 1280: the "song" before the section is mostly speech, so the untranscribed stretch
     * is the tail of a talk the song swallowed, not a lost opening. That is the song-swallows-
     * speech defect, and F11 stays out of it.
     */
    #[Test]
    public function a_song_that_reads_as_speech_is_not_a_lost_opening(): void
    {
        $notices = $this->apply(
            [$this->section('song', 126.0, 414.0), $this->section('notices', 442.2, 600.0)],
            [
                ['start' => 140.0, 'end' => 150.0, 'text' => 'Ride on, ride on in majesty.'],
                ['start' => 442.0, 'end' => 448.0, 'text' => 'and the church meeting is on Thursday.'],
            ],
            [[126, 230, 0.9, 0.1], [230, 600, 0.05, 0.8]],
            600.0,
        )[1];

        $this->assertNotContains(self::QUESTION, $notices->reviewFlags);
    }

    #[Test]
    public function speech_the_transcript_holds_is_not_asked_about(): void
    {
        $prayer = $this->apply(
            [$this->section('song', 2400.0, 2690.0), $this->section('prayer', 2706.4, 2800.0)],
            [
                ['start' => 2690.5, 'end' => 2698.0, 'text' => 'Let us bow our heads'],
                ['start' => 2698.4, 'end' => 2705.0, 'text' => 'and come to the Lord in prayer.'],
                ['start' => 2706.4, 'end' => 2711.9, 'text' => 'Our Heavenly Father,'],
            ],
            [[2400, 2690, 0.9, 0.1], [2690, 2800, 0.05, 0.8]],
            2800.0,
        )[1];

        $this->assertSame([], $prayer->reviewFlags);
    }

    #[Test]
    public function speech_inside_a_recorded_unobservable_window_is_already_marked(): void
    {
        $prayer = $this->apply(
            [$this->section('song', 2400.0, 2690.0), $this->section('prayer', 2706.4, 2800.0)],
            [['start' => 2706.4, 'end' => 2711.9, 'text' => 'pray. Our Heavenly Father,']],
            [[2400, 2690, 0.9, 0.1], [2690, 2800, 0.05, 0.8]],
            2800.0,
            [['start' => 2688.0, 'end' => 2706.0, 'reason' => 'retranscription_failed']],
        )[1];

        $this->assertSame([], $prayer->reviewFlags);
    }

    #[Test]
    public function a_stretch_shorter_than_ten_seconds_is_not_asked_about(): void
    {
        $prayer = $this->apply(
            [$this->section('song', 2400.0, 2697.0), $this->section('prayer', 2706.4, 2800.0)],
            [['start' => 2706.4, 'end' => 2711.9, 'text' => 'pray. Our Heavenly Father,']],
            [[2400, 2700, 0.9, 0.1], [2700, 2800, 0.05, 0.8]],
            2800.0,
        )[1];

        $this->assertSame([], $prayer->reviewFlags);
    }

    /**
     * Only the opening after a song: between two spoken sections the stretch is no section's
     * lost lead-in that a song edge explains.
     */
    #[Test]
    public function a_section_that_does_not_follow_a_song_is_not_asked_about(): void
    {
        $prayer = $this->apply(
            [$this->section('notices', 2400.0, 2690.0), $this->section('prayer', 2706.4, 2800.0)],
            [['start' => 2706.4, 'end' => 2711.9, 'text' => 'pray. Our Heavenly Father,']],
            [[2400, 2690, 0.9, 0.1], [2690, 2800, 0.05, 0.8]],
            2800.0,
        )[1];

        $this->assertSame([], $prayer->reviewFlags);
    }

    /**
     * Ask, don't block: the section is reviewed, but a sermon asked about it still extracts.
     */
    /**
     * About 10 of the 62 corpus windows hold real words in one over-stretched cue (1263's
     * "turn with me please to Hebrews chapter 2"), so the note must not claim the speech went
     * untranscribed (operator 2026-10-05).
     */
    #[Test]
    public function the_note_says_the_speech_has_no_transcribed_line(): void
    {
        $note = UntranscribedSpeechBeforeSection::note(2690.0, 2706.4);

        $this->assertSame(
            'Speech with no transcribed line at 2690.0–2706.4s, before this section\'s first transcribed words: the section\'s opening, or the end of something else?',
            $note,
        );
        $this->assertTrue(UntranscribedSpeechBeforeSection::isNote($note));
        $this->assertFalse(UntranscribedSpeechBeforeSection::isNote('Speech exposed at 2690.0–2706.4s'));
    }

    #[Test]
    public function the_question_sends_the_section_to_review_without_holding_a_sermon(): void
    {
        $this->assertTrue(SectionReviewFlagPolicy::requiresManualReview(ServiceSectionType::Prayer, [self::QUESTION]));
        $this->assertTrue(SermonAutoExtractionPolicy::reviewStatePermitsAutoExtraction(true, [self::QUESTION]));
    }

    /**
     * @param  list<ServiceStructureSection>  $sections
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  list<array{0: float|int, 1: float|int, 2: float, 3: float}>  $timeline
     * @param  list<array{start: float, end: float, reason: string}>  $unobservableWindows
     * @return list<ServiceStructureSection>
     */
    private function apply(array $sections, array $cues, array $timeline, float $audioSeconds, array $unobservableWindows = []): array
    {
        return app(UntranscribedSpeechBeforeSection::class)->apply(
            ServiceStructure::fromSections($sections),
            ChurchServiceTranscript::fromCues($cues, $audioSeconds, ChurchServiceTranscript::SOURCE_MOCK, $unobservableWindows),
            AudioTimeline::fromJson(AudioTimelineFixture::json($timeline, $audioSeconds)),
        )->sections;
    }

    /** @return list<string> */
    private function questionNotes(ServiceStructureSection $section): array
    {
        return array_values(array_filter($section->notes, UntranscribedSpeechBeforeSection::isNote(...)));
    }

    private function section(string $type, float $start, float $end): ServiceStructureSection
    {
        $section = ServiceStructureSection::fromArray(['type' => $type, 'start_time' => $start, 'end_time' => $end, 'confidence' => 0.9]);

        assert($section instanceof ServiceStructureSection);

        return $section;
    }
}
