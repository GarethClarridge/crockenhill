<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Exceptions\SegmentationException;
use App\Services\Media\Audio\RmsAnalysisService;
use App\Services\Media\Audio\SustainedSound;

/**
 * Singing the transcript cannot see, recovered from the RMS log.
 *
 * Whisper leaves congregational singing as an unobservable window or a "Thank you."/"Amen."
 * loop, so a transcript-only detector either cuts a song section to the lines it did
 * transcribe or returns no section for the song at all. The §4.1b section coverage census
 * (2026-09-14) found eight songs cut short and ten with no section. {@see SustainedSound}
 * tells singing from speech without the transcript.
 *
 * Two repairs follow, both confined to time no section holds:
 *  - a song section widens across sustained sound beside it;
 *  - sustained sound beside no song becomes a proposed song section, held for review.
 *
 * Sound running on into a neighbouring prayer or reading is left alone. Fresh audio heard 21
 * of those 38 corpus cases as speech: a leader at a microphone is as loud and unbroken as
 * singing, so the level cannot say where a song gives way to a section already typed.
 */
class SustainedSoundSongSections
{
    public const PROPOSED_TITLE = 'Unidentified singing';

    /**
     * Every corpus widening under 30 s that fresh audio heard as speech was 25 s or less; of the
     * 24 unadjudicated widenings of 30 s or more, none was speech.
     */
    private const MINIMUM_WIDENING_SECONDS = 30.0;

    /**
     * A short silence between two songs does not survive the smoothing window, so a long
     * widening can swallow the next song: 1244 §3037 would take 265 s and 985 §1121 160 s of
     * another song. The real truncations widen 40-150 s, so length alone cannot decide, and a
     * widening past this is kept but held for review.
     */
    private const REVIEWED_WIDENING_SECONDS = 90.0;

    /**
     * An announced song's section can hold only the announcement, with a few seconds of
     * introduction before the singing is sustained (1341 §4310, 1231 §2851: 10 s each). The
     * corpus census of 448 runs found no other edge within 20 s of sustained sound, and the one
     * at 20 s (1287 §3597) is a displaced song identity, not an introduction. A bridged widening
     * is always held: it is an inference about the gap, not only about the sound.
     */
    private const INTRODUCTION_BINS = 2;

    private const MINIMUM_PROPOSAL_SECONDS = 45.0;

    private const PROPOSAL_CONFIDENCE = 0.5;

    public function __construct(private readonly RmsAnalysisService $rmsAnalysisService) {}

    /**
     * @param  string  $rmsLogContent  Raw contents of the rms_log_path artifact
     * @param  bool  $recordingOmitsSongs  A concatenated recording had its songs cut out before assembly
     */
    public function apply(ServiceStructure $structure, string $rmsLogContent, bool $recordingOmitsSongs): ServiceStructure
    {
        if ($recordingOmitsSongs || $structure->isEmpty()) {
            return $structure;
        }

        $sound = $this->sustainedSound($rmsLogContent);

        if (! $sound instanceof SustainedSound) {
            return $structure;
        }

        $sections = $this->widenSongs($structure->sections, $sound);
        $sections = $this->proposeSongs($sections, $sound);

        if ($sections === $structure->sections) {
            return $structure;
        }

        return ServiceStructure::fromSections(
            $sections,
            $structure->notes,
            $structure->model,
            $structure->summary,
            $structure->notices,
            $structure->chapterMarkers,
            $structure->sermonAbsence,
        );
    }

    /**
     * Widen each song across the sustained sound beside it, judged against the sections as
     * detected so that one song's widening never decides another's.
     *
     * @param  list<ServiceStructureSection>  $sections
     * @return list<ServiceStructureSection>
     */
    private function widenSongs(array $sections, SustainedSound $sound): array
    {
        $widened = $sections;

        foreach ($sections as $index => $section) {
            if ($section->type !== ServiceSectionType::Song) {
                continue;
            }

            [$start, $bridgedStart] = $this->widenedStart($sections, $index, $sound) ?? [$section->startTime, false];
            [$end, $bridgedEnd] = $this->widenedEnd($sections, $index, $sound) ?? [$section->endTime, false];
            $growth = ($section->startTime - $start) + ($end - $section->endTime);

            if ($growth <= 0.0) {
                continue;
            }

            $notes = [];

            if ($start < $section->startTime) {
                $notes[] = sprintf('Start widened %+.1fs across sustained sound to %.1fs; the transcript shows no singing there.', $start - $section->startTime, $start);
            }

            if ($end > $section->endTime) {
                $notes[] = sprintf('End widened %+.1fs across sustained sound to %.1fs; the transcript shows no singing there.', $end - $section->endTime, $end);
            }

            if ($bridgedStart || $bridgedEnd) {
                $notes[] = 'The widening crossed a short introduction between the announcement and the singing.';
            }

            $widened[$index] = $section->withTimes($start, $end, $notes);

            if ($bridgedStart || $bridgedEnd || max($section->startTime - $start, $end - $section->endTime) > self::REVIEWED_WIDENING_SECONDS) {
                $widened[$index] = $widened[$index]->withReviewFlags([ServiceStructureValidator::FLAG_SONG_WIDENED_TO_SUSTAINED_SOUND]);
            }
        }

        return array_values($widened);
    }

    /**
     * @param  list<ServiceStructureSection>  $sections
     * @return array{0: float, 1: bool}|null The new end, and whether it crossed an introduction
     */
    private function widenedEnd(array $sections, int $index, SustainedSound $sound): ?array
    {
        $section = $sections[$index];
        $firstBin = $this->firstSustainedBin($sections, $index, $sound, (int) floor($section->endTime / SustainedSound::BIN_SECONDS), 1);

        if ($firstBin === null) {
            return null;
        }

        $lastBin = null;

        for ($bin = $firstBin; $bin < $sound->binCount() && $sound->isSustainedBin($bin); $bin++) {
            $holder = $this->holderAt($sections, ($bin + 0.5) * SustainedSound::BIN_SECONDS, $index);

            if ($holder instanceof ServiceStructureSection) {
                if ($holder->type === ServiceSectionType::Song) {
                    return null;
                }

                break;
            }

            $lastBin = $bin;
        }

        if ($lastBin === null) {
            return null;
        }

        $nextStart = $sound->audioEnd;

        foreach ($sections as $otherIndex => $other) {
            if ($otherIndex !== $index && $other->startTime >= $section->endTime) {
                $nextStart = min($nextStart, $other->startTime);
            }
        }

        $end = min(($lastBin + 1) * SustainedSound::BIN_SECONDS, $nextStart);
        $sustainedFrom = max($section->endTime, $firstBin * SustainedSound::BIN_SECONDS);

        return $end - $sustainedFrom >= self::MINIMUM_WIDENING_SECONDS
            ? [$end, $sustainedFrom > $section->endTime]
            : null;
    }

    /**
     * @param  list<ServiceStructureSection>  $sections
     * @return array{0: float, 1: bool}|null The new start, and whether it crossed an introduction
     */
    private function widenedStart(array $sections, int $index, SustainedSound $sound): ?array
    {
        $section = $sections[$index];
        $lastBin = $this->firstSustainedBin($sections, $index, $sound, (int) ceil($section->startTime / SustainedSound::BIN_SECONDS) - 1, -1);

        if ($lastBin === null) {
            return null;
        }

        $firstBin = null;

        for ($bin = $lastBin; $bin >= 0 && $bin < $sound->binCount() && $sound->isSustainedBin($bin); $bin--) {
            $holder = $this->holderAt($sections, ($bin + 0.5) * SustainedSound::BIN_SECONDS, $index);

            if ($holder instanceof ServiceStructureSection) {
                if ($holder->type === ServiceSectionType::Song) {
                    return null;
                }

                break;
            }

            $firstBin = $bin;
        }

        if ($firstBin === null) {
            return null;
        }

        $previousEnd = 0.0;

        foreach ($sections as $otherIndex => $other) {
            if ($otherIndex !== $index && $other->endTime <= $section->startTime) {
                $previousEnd = max($previousEnd, $other->endTime);
            }
        }

        $start = max($firstBin * SustainedSound::BIN_SECONDS, $previousEnd);
        $sustainedTo = min($section->startTime, ($lastBin + 1) * SustainedSound::BIN_SECONDS);

        return $sustainedTo - $start >= self::MINIMUM_WIDENING_SECONDS
            ? [$start, $sustainedTo < $section->startTime]
            : null;
    }

    /**
     * The first sustained bin met walking away from a song's edge, across at most an
     * introduction's worth of unheld, unsustained bins.
     *
     * @param  list<ServiceStructureSection>  $sections
     * @param  1|-1  $direction
     */
    private function firstSustainedBin(array $sections, int $index, SustainedSound $sound, int $edgeBin, int $direction): ?int
    {
        for ($step = 0; $step <= self::INTRODUCTION_BINS; $step++) {
            $bin = $edgeBin + $step * $direction;

            if ($bin < 0 || $bin >= $sound->binCount()) {
                return null;
            }

            if ($sound->isSustainedBin($bin)) {
                return $bin;
            }

            if ($this->holderAt($sections, ($bin + 0.5) * SustainedSound::BIN_SECONDS, $index) instanceof ServiceStructureSection) {
                return null;
            }
        }

        return null;
    }

    /**
     * Propose a held song over each run of sustained sound no section holds, after the first
     * section and beside no song.
     *
     * Before the first section is excluded because music before a service is as loud as an
     * opening song. Beside a song, the widening has either taken the sound or refused it: it
     * was too short to trust, or it ran on into another song and belongs to one of the two.
     *
     * @param  list<ServiceStructureSection>  $sections
     * @return list<ServiceStructureSection>
     */
    private function proposeSongs(array $sections, SustainedSound $sound): array
    {
        $firstStart = INF;

        foreach ($sections as $section) {
            $firstStart = min($firstStart, $section->startTime);
        }

        $proposed = [];
        $openBin = null;

        for ($bin = 0; $bin <= $sound->binCount(); $bin++) {
            $mid = ($bin + 0.5) * SustainedSound::BIN_SECONDS;
            $unheld = $sound->isSustainedBin($bin)
                && $mid > $firstStart
                && ! $this->holderAt($sections, $mid, null) instanceof ServiceStructureSection;

            if ($unheld) {
                $openBin ??= $bin;

                continue;
            }

            if ($openBin !== null) {
                $proposal = $this->proposal($sections, $openBin * SustainedSound::BIN_SECONDS, $bin * SustainedSound::BIN_SECONDS, $sound->audioEnd);

                if ($proposal instanceof ServiceStructureSection) {
                    $proposed[] = $proposal;
                }

                $openBin = null;
            }
        }

        return [...$sections, ...$proposed];
    }

    /**
     * @param  list<ServiceStructureSection>  $sections
     */
    private function proposal(array $sections, float $from, float $to, float $audioEnd): ?ServiceStructureSection
    {
        $start = $from;
        $end = min($to, $audioEnd);

        foreach ($sections as $section) {
            if ($section->endTime <= $from + SustainedSound::BIN_SECONDS) {
                $start = max($start, $section->endTime);
            }

            if ($section->startTime >= $to - SustainedSound::BIN_SECONDS) {
                $end = min($end, $section->startTime);
            }
        }

        if ($end - $start < self::MINIMUM_PROPOSAL_SECONDS) {
            return null;
        }

        foreach ($sections as $section) {
            if ($section->type === ServiceSectionType::Song
                && (abs($section->endTime - $start) <= SustainedSound::BIN_SECONDS || abs($section->startTime - $end) <= SustainedSound::BIN_SECONDS)) {
                return null;
            }
        }

        return new ServiceStructureSection(
            type: ServiceSectionType::Song,
            title: self::PROPOSED_TITLE,
            startTime: $start,
            endTime: $end,
            confidence: self::PROPOSAL_CONFIDENCE,
            oosItemId: null,
            songTitle: null,
            readingReference: null,
            notes: [sprintf(
                'Proposed over %.0fs of sustained sound no section held (%.1fs-%.1fs); the transcript shows no singing there, so the song is unidentified.',
                $end - $start,
                $start,
                $end,
            )],
            reviewFlags: [ServiceStructureValidator::FLAG_UNIDENTIFIED_SINGING],
        );
    }

    /**
     * @param  list<ServiceStructureSection>  $sections
     */
    private function holderAt(array $sections, float $time, ?int $exceptIndex): ?ServiceStructureSection
    {
        foreach ($sections as $index => $section) {
            if ($index !== $exceptIndex && $section->startTime <= $time && $section->endTime > $time) {
                return $section;
            }
        }

        return null;
    }

    private function sustainedSound(string $rmsLogContent): ?SustainedSound
    {
        $samples = $this->rmsAnalysisService->extractRmsData($rmsLogContent);

        if ($samples === []) {
            return null;
        }

        try {
            $threshold = (float) $this->rmsAnalysisService->determineThreshold($rmsLogContent)['threshold'];
        } catch (SegmentationException) {
            $threshold = $this->rmsAnalysisService->getRmsThreshold();
        }

        return SustainedSound::fromSamples($samples, $threshold);
    }
}
