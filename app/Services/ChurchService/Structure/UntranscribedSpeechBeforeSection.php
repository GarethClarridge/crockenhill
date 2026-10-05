<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Enums\SoundClass;
use App\Services\ChurchService\SectionPublication\SongPublicationBoundaryEvidenceService;
use App\Services\Media\Audio\AudioTimeline;

/**
 * Speech the classifier hears after a song that the transcript holds no words for (F11).
 *
 * Whisper often loses the first sentence after a hymn: run 949's prayer opens "pray. Our
 * Heavenly Father…" with 16 s of speech and no cue before it; 1025's sits under one 30 s
 * "Thank you." filler cue. Measured read-only on 2026-10-05 over 996 section starts after a
 * song: 8.2% hit, and re-decoding reduces it without removing it.
 *
 * The stretch is the speech, unbroken by any cue of {@see self::LONGEST_TRANSCRIBED_CUE_SECONDS}
 * or less or a recorded unobservable window, that runs up to the section's first transcribed
 * words. Only what abuts those words: a gap further back between lines the transcript does hold
 * is not the section's opening.
 *
 * The section is asked about it and nothing moves (operator 2026-10-05: mark + ask). The stretch
 * is either the section's own opening or the tail of something else, and the sound cannot say
 * which. The interval travels in a note, the record a later re-decode targets.
 *
 * Not marked as a transcript unobservable window: the readers of those take them as sound
 * nobody heard ({@see SongPublicationBoundaryEvidenceService}
 * stops trusting a song end beside one), while this is speech the classifier did hear.
 */
class UntranscribedSpeechBeforeSection
{
    /**
     * The classifier's speech score a window must reach. Requiring singing ≤ 0.2 as well, as the
     * measurement did, changed none of its 82 hits, and the timeline does not carry singing.
     */
    private const MINIMUM_SPEECH_SCORE = 0.6;

    /**
     * Whisper's filler cues ("Thank you.", ". . .", a looped line) run its full 30 s window; a
     * real line is far shorter.
     */
    private const LONGEST_TRANSCRIBED_CUE_SECONDS = 15.0;

    private const MINIMUM_STRETCH_SECONDS = 10.0;

    /**
     * A cue ending this close before the first one is the same utterance (993's "the last" before
     * "part of Joshua chapter 5").
     */
    private const SAME_UTTERANCE_GAP_SECONDS = 1.0;

    /**
     * A "song" that reads as speech for at least this share swallowed a talk: its spoken tail
     * is that defect, not a lost opening (1153 1.0, 1119 0.68, 1280 0.52; every other hit 0.33
     * or less).
     */
    private const MAXIMUM_SONG_SPEECH_SHARE = 0.5;

    private const NOTE_PREFIX = 'Untranscribed speech at';

    /**
     * @param  AudioTimeline|null  $timeline  Null when re-deriving flags from banked structure,
     *                                        which cannot judge this and leaves it alone
     */
    public function apply(ServiceStructure $structure, ChurchServiceTranscript $transcript, ?AudioTimeline $timeline): ServiceStructure
    {
        if (! $timeline instanceof AudioTimeline) {
            return $structure;
        }

        $sections = $structure->sections;

        foreach ($structure->sections as $index => $section) {
            $song = $structure->sections[$index - 1] ?? null;

            if ($song?->type !== ServiceSectionType::Song || $section->type === ServiceSectionType::Song) {
                continue;
            }

            $stretch = $this->stretchBefore($section, $song, $transcript, $timeline);

            if ($stretch === null) {
                continue;
            }

            $sections[$index] = $section->withReviewFlags(
                [ServiceStructureValidator::FLAG_UNTRANSCRIBED_SPEECH_BEFORE_SECTION],
                [self::note(...$stretch)],
            );
        }

        if ($sections === $structure->sections) {
            return $structure;
        }

        return ServiceStructure::fromSections(
            array_values($sections),
            $structure->notes,
            $structure->model,
            $structure->summary,
            $structure->notices,
            $structure->chapterMarkers,
            $structure->sermonAbsence,
        );
    }

    /**
     * The interval the question asks about: its only record, so the ensemble carries it wherever
     * the question goes ({@see self::isNote()}).
     */
    public static function note(float $from, float $to): string
    {
        return sprintf(self::NOTE_PREFIX.' %.1f–%.1fs before this section\'s first transcribed words: its opening, lost to the transcript, or the end of something else?', $from, $to);
    }

    public static function isNote(string $note): bool
    {
        return str_starts_with($note, self::NOTE_PREFIX.' ');
    }

    /**
     * @return array{0: float, 1: float}|null
     */
    private function stretchBefore(
        ServiceStructureSection $section,
        ServiceStructureSection $song,
        ChurchServiceTranscript $transcript,
        AudioTimeline $timeline,
    ): ?array {
        $lines = array_values(array_filter(
            $transcript->cues,
            static fn (array $cue): bool => $cue['end'] - $cue['start'] <= self::LONGEST_TRANSCRIBED_CUE_SECONDS,
        ));
        $first = null;

        foreach ($lines as $line) {
            if (($line['start'] + $line['end']) / 2 >= $section->startTime) {
                $first = $line;

                break;
            }
        }

        if ($first === null || $first['start'] > $section->endTime) {
            return null;
        }

        $edge = $this->openingOf($first['start'], $lines, $song->startTime);
        $floor = $song->startTime;

        foreach ([...$lines, ...$transcript->unobservableWindows] as $covered) {
            if ($covered['start'] < $edge) {
                $floor = max($floor, min($covered['end'], $edge));
            }
        }

        $start = $edge;

        for ($window = $timeline->windowAt(max(0.0, $edge - 0.001)); $window !== null && $window >= 0; $window--) {
            $scores = $timeline->windows[$window];

            if ($scores['end'] <= $floor || $scores['speech'] < self::MINIMUM_SPEECH_SCORE) {
                break;
            }

            $start = max($scores['start'], $floor);
        }

        if ($edge - $start < self::MINIMUM_STRETCH_SECONDS) {
            return null;
        }

        if ($timeline->classShare(SoundClass::Speech, $song->startTime, $song->endTime) >= self::MAXIMUM_SONG_SPEECH_SHARE) {
            return null;
        }

        return [$start, $edge];
    }

    /**
     * Where the utterance holding the first line begins: earlier lines ending within
     * {@see self::SAME_UTTERANCE_GAP_SECONDS} of it chain it back, never past the song's start.
     *
     * @param  list<array{start: float, end: float, text: string}>  $lines
     */
    private function openingOf(float $edge, array $lines, float $songStart): float
    {
        do {
            $chained = $edge;

            foreach ($lines as $line) {
                if ($line['start'] > $songStart && $line['start'] < $edge && $line['end'] >= $edge - self::SAME_UTTERANCE_GAP_SECONDS) {
                    $chained = min($chained, $line['start']);
                }
            }

            $moved = $chained < $edge;
            $edge = $chained;
        } while ($moved);

        return $edge;
    }
}
