<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Exceptions\SegmentationException;
use App\Services\Media\Audio\RmsAnalysisService;
use App\Services\Media\Audio\SustainedSound;
use App\Services\Scripture\ScriptureReferenceResolver;
use App\Services\Sermon\SermonExtractionPlanResolver;

/**
 * Speech the detector left inside a song section, trimmed off its ends.
 *
 * The detector starts a song where the leader announces it, so the clip opens on "Let's stand
 * and sing…". The 2026-09-16 blind source comparison found 19-60 s of speech on 7 of 23 songs,
 * and every one of 20 corpus lead-ins of 25 s or more sampled on 2026-09-17 was an announcement,
 * with continuous singing from the measured onset. Replayed over 1,177 songs, the rule trims
 * about a third of them, so holding each for review is not a workable answer.
 *
 * The onset is where {@see SustainedSound}, on a 10 s window, first calls a bin sung. A bin only
 * reads as sung once the bin before it does too, so the onset lands late — 1-9 s on the blind
 * set — and a start trim keeps two bins of margin: with one, 4682 lost 4 s and 1688 1 s of their
 * first lines. The same lag makes the measured end late, so an end trim keeps one bin.
 *
 * Pauses, not level, separate the two: a leader at a microphone is as loud as the congregation,
 * which is also why {@see SustainedSoundSongSections} leaves sound running into a typed neighbour
 * alone.
 *
 * Only where the measure works for the run. On some recordings the singing reads as speech
 * against the run's own threshold (1267, 1235 and 1309 have published songs that do), and there
 * the other songs do not read as sung either.
 */
class SongSpeechEdges
{
    /**
     * Below this the onset error is too close to the lead-in itself; the corpus sample put one
     * clean song (1314 §3988) at exactly 25 s.
     */
    private const MINIMUM_LEAD_SECONDS = 25.0;

    /**
     * Both measured spoken tails (1221 §2719, 1336 §4264) ran into the following prayer at 22-24 s;
     * no song known to be clean showed a tail over 3 s.
     */
    private const MINIMUM_TAIL_SECONDS = 20.0;

    private const START_MARGIN_SECONDS = 2 * SustainedSound::BIN_SECONDS;

    private const END_MARGIN_SECONDS = SustainedSound::BIN_SECONDS;

    /**
     * Songs read at least 0.84 sustained on the long window where the measure works.
     */
    private const MINIMUM_OTHER_SONGS_SHARE = 0.6;

    /**
     * A section mostly of speech is a different problem — a song swallowing a talk or prayer —
     * that trimming one end would not fix, so it is held instead ({@see self::heldIfSpokenAtAnEdge()}).
     */
    private const MINIMUM_SECTION_SHARE = 0.5;

    private const MINIMUM_REMAINING_SECONDS = 30.0;

    private const EXPOSED_SPEECH_NOTE = 'Speech at';

    public function __construct(
        private readonly RmsAnalysisService $rmsAnalysisService,
        private readonly ScriptureReferenceResolver $scriptureReferences,
    ) {}

    /**
     * @param  string  $rmsLogContent  Raw contents of the rms_log_path artifact
     * @param  bool  $recordingOmitsSongs  A concatenated recording had its songs cut out before assembly
     */
    public function apply(ServiceStructure $structure, string $rmsLogContent, bool $recordingOmitsSongs): ServiceStructure
    {
        if ($recordingOmitsSongs || $structure->isEmpty()) {
            return $structure;
        }

        $samples = $this->rmsAnalysisService->extractRmsData($rmsLogContent);

        if ($samples === []) {
            return $structure;
        }

        $threshold = $this->threshold($rmsLogContent);
        $song = SustainedSound::fromSamples($samples, $threshold);
        $edge = SustainedSound::fromSamples($samples, $threshold, SustainedSound::EDGE_WINDOW_BINS);

        if (! $song instanceof SustainedSound || ! $edge instanceof SustainedSound) {
            return $structure;
        }

        $sections = $structure->sections;

        foreach ($structure->sections as $index => $section) {
            if ($section->type !== ServiceSectionType::Song || ! $this->otherSongsReadAsSung($structure->sections, $index, $song)) {
                continue;
            }

            $sections[$index] = $song->share($section->startTime, $section->endTime) >= self::MINIMUM_SECTION_SHARE
                ? $this->trimmed($section, $edge)
                : $this->heldIfSpokenAtAnEdge($section, $edge);
        }

        if ($sections === $structure->sections) {
            return $structure;
        }

        $sections = $this->sermonsAskedAboutExposedSpeech($structure->sections, $sections);

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
     * The interval the ownership question asks about: its only record, so the ensemble carries
     * it wherever the question goes ({@see self::isExposedSpeechNote()}).
     */
    public static function exposedSpeechNote(float $from, float $to, ServiceSectionType $beside = ServiceSectionType::Sermon): string
    {
        $owner = match ($beside) {
            ServiceSectionType::BibleReading => 'the reading\'s',
            ServiceSectionType::Prayer => 'the concluding prayer\'s',
            default => 'the sermon\'s',
        };

        return sprintf(self::EXPOSED_SPEECH_NOTE.' %.1f–%.1fs, trimmed off the adjacent song, belongs to no section: %s own words or an excluded announcement?', $from, $to, $owner);
    }

    public static function isExposedSpeechNote(string $note): bool
    {
        return str_starts_with($note, self::EXPOSED_SPEECH_NOTE.' ');
    }

    /**
     * A trim leaves the stretch it cut off unowned. Next to a section the sermon's media is cut
     * from, that stretch is either its own opening or conclusion, swallowed by the song, or an
     * announcement rightly excluded (D1); the sound cannot tell which, so the sermon is asked
     * about it and nothing absorbs it or changes bounds.
     *
     * @param  list<ServiceStructureSection>  $before
     * @param  array<int, ServiceStructureSection>  $after  Indexed alike, one trimmed or held song at a time
     * @return array<int, ServiceStructureSection>
     */
    private function sermonsAskedAboutExposedSpeech(array $before, array $after): array
    {
        $output = $this->sermonOutput($before);

        foreach ($after as $index => $song) {
            $original = $before[$index];

            if ($song === $original || $song->type !== ServiceSectionType::Song) {
                continue;
            }

            $exposed = [];

            if ($song->startTime > $original->startTime && isset($output[$index - 1])) {
                $exposed[] = [$output[$index - 1], $before[$index - 1]->type, $original->startTime, $song->startTime];
            }

            if ($song->endTime < $original->endTime && isset($output[$index + 1])) {
                $exposed[] = [$output[$index + 1], $before[$index + 1]->type, $song->endTime, $original->endTime];
            }

            foreach ($exposed as [$sermonIndex, $beside, $from, $to]) {
                $after[$sermonIndex] = $after[$sermonIndex]->withReviewFlags(
                    [ServiceStructureValidator::FLAG_SERMON_ADJACENT_SPEECH_UNOWNED],
                    [self::exposedSpeechNote($from, $to, $beside)],
                );
            }
        }

        return $after;
    }

    /**
     * The sections the sermon's media may be cut from, each mapped to the sermon asked about it:
     * every sermon section, the readings before the first that reading membership could cut, and
     * the concluding prayer — the same rules {@see SermonExtractionPlanResolver::compose()}
     * selects by.
     *
     * @param  list<ServiceStructureSection>  $sections
     * @return array<int, int>
     */
    private function sermonOutput(array $sections): array
    {
        $sermons = array_keys(array_filter($sections, static fn (ServiceStructureSection $section): bool => $section->type === ServiceSectionType::Sermon));

        if ($sermons === []) {
            return [];
        }

        $output = array_combine($sermons, $sermons);
        $first = $sermons[0];
        $last = $sermons[count($sermons) - 1];
        $readings = [];

        foreach ($sections as $index => $section) {
            if ($section->type === ServiceSectionType::BibleReading && $section->startTime < $sections[$first]->startTime) {
                $readings[$index] = $section->readingReference;
            }
        }

        foreach ($this->scriptureReferences->sermonReadingMembership($sections[$first]->sermonReference, $readings)['could_be_cut'] as $reading) {
            $output[$reading] = $first;
        }

        $beforeSong = [];

        foreach (array_slice($sections, $last + 1, null, true) as $index => $section) {
            if ($section->type === ServiceSectionType::Song) {
                break;
            }

            $beforeSong[$index] = $section->type;
        }

        $prayers = array_keys($beforeSong, ServiceSectionType::Prayer, true);

        if (count($prayers) === 1 && $prayers[0] === $last + 1) {
            $output[$last + 1] = $last;
        }

        return $output;
    }

    /**
     * @param  list<ServiceStructureSection>  $sections
     */
    private function otherSongsReadAsSung(array $sections, int $index, SustainedSound $song): bool
    {
        $others = [];

        foreach ($sections as $otherIndex => $other) {
            if ($otherIndex !== $index && $other->type === ServiceSectionType::Song) {
                $others[] = $song->share($other->startTime, $other->endTime);
            }
        }

        return $others !== [] && $this->median($others) >= self::MINIMUM_OTHER_SONGS_SHARE;
    }

    /**
     * A mostly spoken song section with a long spoken edge: held, never trimmed.
     *
     * 974 §988 swallowed a prayer — 93 s of speech before the singing, 0.48 sustained — and 1475
     * a 104 s lead-in and a 41 s tail at 0.42. Below half sustained the section is a song that
     * absorbed a separate item, and cutting one end would guess where that item stops. The same
     * lead and tail floors as the trim say which edge is spoken.
     */
    private function heldIfSpokenAtAnEdge(ServiceStructureSection $section, SustainedSound $edge): ServiceStructureSection
    {
        [$onset, $offset] = $this->sungExtent($section, $edge);

        if ($onset === null || $offset === null) {
            return $section;
        }

        $lead = $onset - $section->startTime;
        $tail = $section->endTime - $offset;

        if ($lead < self::MINIMUM_LEAD_SECONDS && $tail < self::MINIMUM_TAIL_SECONDS) {
            return $section;
        }

        return $section->withReviewFlags([ServiceStructureValidator::FLAG_SONG_SWALLOWS_SPEECH], [sprintf(
            'Held, not trimmed: under half the section is sung, with %.0fs spoken before the singing and %.0fs after.',
            $lead,
            $tail,
        )]);
    }

    /**
     * Where singing first and last reads as sustained on the edge window, or nulls when it never does.
     *
     * @return array{0: float|null, 1: float|null}
     */
    private function sungExtent(ServiceStructureSection $section, SustainedSound $edge): array
    {
        $firstBin = (int) floor($section->startTime / SustainedSound::BIN_SECONDS);
        $lastBin = min($edge->binCount() - 1, (int) floor(($section->endTime - 0.001) / SustainedSound::BIN_SECONDS));
        $onset = null;
        $offset = null;

        for ($bin = $firstBin; $bin <= $lastBin; $bin++) {
            if ($edge->isSustainedBin($bin)) {
                $onset ??= max($bin * SustainedSound::BIN_SECONDS, $section->startTime);
                $offset = min(($bin + 1) * SustainedSound::BIN_SECONDS, $section->endTime);
            }
        }

        return [$onset, $offset];
    }

    private function trimmed(ServiceStructureSection $section, SustainedSound $edge): ServiceStructureSection
    {
        [$onset, $offset] = $this->sungExtent($section, $edge);

        if ($onset === null || $offset === null) {
            return $section;
        }

        $start = $onset - $section->startTime >= self::MINIMUM_LEAD_SECONDS
            ? $onset - self::START_MARGIN_SECONDS
            : $section->startTime;
        $end = $section->endTime - $offset >= self::MINIMUM_TAIL_SECONDS
            ? $offset + self::END_MARGIN_SECONDS
            : $section->endTime;

        if (($start === $section->startTime && $end === $section->endTime) || $end - $start < self::MINIMUM_REMAINING_SECONDS) {
            return $section;
        }

        $notes = [];

        if ($start > $section->startTime) {
            $notes[] = sprintf('Start trimmed %+.1fs to %.1fs: the sound before it has the pauses of speech, not singing.', $start - $section->startTime, $start);
        }

        if ($end < $section->endTime) {
            $notes[] = sprintf('End trimmed %+.1fs to %.1fs: the sound after it has the pauses of speech, not singing.', $end - $section->endTime, $end);
        }

        return $section->withTimes($start, $end, $notes);
    }

    private function threshold(string $rmsLogContent): float
    {
        try {
            return (float) $this->rmsAnalysisService->determineThreshold($rmsLogContent)['threshold'];
        } catch (SegmentationException) {
            return $this->rmsAnalysisService->getRmsThreshold();
        }
    }

    /**
     * @param  non-empty-list<float>  $values
     */
    private function median(array $values): float
    {
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
