<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Services\Media\Audio\RmsAnalysisService;
use App\Services\Media\Audio\SustainedSound;
use App\Services\Sermon\SermonExtractionPlanResolver;
use App\Support\SermonAutoExtractionPolicy;

/**
 * Singing inside a sermon section that no song section holds.
 *
 * Sermon 885 is the case: run 949's closing hymn (4635–4795 s) sits wholly inside sermon §723
 * (2744–4827 s), inside an unobservable transcript window, so no lyric test can see it and the
 * published sermon carries the hymn. {@see MistypedSungSections} cannot reach it, because the
 * hymn is not a section at all.
 *
 * Measured 2026-09-16 over 437 runs (`sungpass-20260916-measure.json`): sustained spans of
 * {@see self::MINIMUM_SPAN_SECONDS} or more inside a sermon and outside every song section
 * number 272 across 150 runs, nearly all preaching at 104–181 wpm. Under
 * {@see self::MAXIMUM_WORDS_PER_MINUTE} leaves two — run 949's hymn and run 1014 §1300's
 * "Thank you ×4" ASR artefact, both genuine — and the count is stable from <20 to <60 wpm.
 *
 * The flag is non-disqualifying in {@see SermonAutoExtractionPolicy}, so the section check
 * alone would let the cut through: the hold is the `sermon_contains_sung_span` composition risk
 * {@see SermonExtractionPlanResolver::compose()} raises, which the operator answers with a
 * composition review keyed to the plan's inputs. The 10-02 cut-from-sections rewrite dropped
 * that risk once and nothing failed; the resolver test pins it.
 */
class SungSpanInsideSermon
{
    private const MINIMUM_SPAN_SECONDS = 30.0;

    private const MAXIMUM_WORDS_PER_MINUTE = 40.0;

    public function __construct(private readonly RmsAnalysisService $rmsAnalysisService) {}

    /**
     * @param  string  $rmsLogContent  Raw contents of the rms_log_path artifact
     */
    public function apply(ServiceStructure $structure, string $rmsLogContent, ChurchServiceTranscript $transcript): ServiceStructure
    {
        if ($structure->isEmpty()) {
            return $structure;
        }

        $sound = SustainedSound::fromRmsLog($rmsLogContent, $this->rmsAnalysisService);

        if (! $sound instanceof SustainedSound) {
            return $structure;
        }

        $songs = array_values(array_filter(
            $structure->sections,
            static fn (ServiceStructureSection $section): bool => $section->type === ServiceSectionType::Song,
        ));
        $sections = $structure->sections;

        foreach ($structure->sections as $index => $section) {
            if ($section->type !== ServiceSectionType::Sermon) {
                continue;
            }

            $notes = [];

            foreach ($this->unheldSustainedSpans($section, $sound, $songs) as [$from, $to]) {
                $wordsPerMinute = str_word_count($transcript->sliceText($from, $to)) / (($to - $from) / 60.0);

                if ($wordsPerMinute < self::MAXIMUM_WORDS_PER_MINUTE) {
                    $notes[] = sprintf('Sung span %.0f–%.0f s inside the sermon at %.0f wpm, held by no song section.', $from, $to, $wordsPerMinute);
                }
            }

            if ($notes !== []) {
                $sections[$index] = $section->withReviewFlags([ServiceStructureValidator::FLAG_SERMON_CONTAINS_SUNG_SPAN], $notes);
            }
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
     * Maximal runs of sustained 5 s bins inside the sermon and outside every song section.
     *
     * @param  list<ServiceStructureSection>  $songs
     * @return list<array{0: float, 1: float}>
     */
    private function unheldSustainedSpans(ServiceStructureSection $sermon, SustainedSound $sound, array $songs): array
    {
        $spans = [];
        $runStart = null;
        $firstBin = (int) ceil($sermon->startTime / SustainedSound::BIN_SECONDS);
        $lastBin = (int) floor($sermon->endTime / SustainedSound::BIN_SECONDS) - 1;

        for ($bin = $firstBin; $bin <= $lastBin + 1; $bin++) {
            $time = $bin * SustainedSound::BIN_SECONDS;
            $open = $bin <= $lastBin && $sound->isSustainedBin($bin) && ! $this->heldBySong($time, $songs);

            if ($open) {
                $runStart ??= $time;

                continue;
            }

            if ($runStart !== null && $time - $runStart >= self::MINIMUM_SPAN_SECONDS) {
                $spans[] = [$runStart, $time];
            }

            $runStart = null;
        }

        return $spans;
    }

    /**
     * @param  list<ServiceStructureSection>  $songs
     */
    private function heldBySong(float $binStart, array $songs): bool
    {
        $binEnd = $binStart + SustainedSound::BIN_SECONDS;

        foreach ($songs as $song) {
            if ($song->startTime < $binEnd && $song->endTime > $binStart) {
                return true;
            }
        }

        return false;
    }
}
