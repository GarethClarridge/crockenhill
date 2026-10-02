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
 * Congregational singing typed as something other than a song.
 *
 * §4.1a named five sections as sung items typed otherwise. Measured against the sound on
 * 2026-09-16 (`sungpass-20260916-measure.json`), only one of them is sung: §1301 "Lo He Comes
 * With Clouds Descending", 290 s typed `other` on run 1014. The rest are a spoken Psalm 46
 * reading whose words match a metrical psalm, two hymn verses read aloud *as* a prayer, an ASR
 * loop, and a hymn announced but never sung — the recording ends before the singing.
 *
 * The lesson is that a lyric scorer over non-song sections mostly finds *spoken* hymn and psalm
 * text, because reading such text aloud is normal in this corpus by design. Sound is the better
 * instrument, but not alone: judged against each run's own noise floor, 48 non-song sections read
 * as fully active, nearly all of them notices and welcomes at conversational speed. Word rate is
 * the second axis. §1301 runs at 44 words a minute; the spoken sections run 88–227.
 *
 * Both together leave 13 sections across 437 services. Nine of those are wordless recorded
 * audio — pre-service music, opening audio, a post-service activity — which are not mistyped at
 * all; they are simply not speech. What separates §1301 from them is where it sits: exactly one
 * of the 13 falls inside the span a sermon absorbs, and that is the harm worth preventing,
 * because run 1014's sermon media (0–1501 s) contains the hymn. That second test belongs to
 * {@see SermonExtractionPlanResolver}, which knows the absorbed span; when
 * structure is detected the span does not exist yet. This class only says which sections read as
 * sung, and is deliberately quiet about what that means.
 *
 * Never applied to a song, sermon or children's talk. A song is not mistyped whatever its sound,
 * and a sermon carrying an unregistered review flag would fail
 * {@see SermonAutoExtractionPolicy}'s final test and quietly stop extracting.
 */
class MistypedSungSections
{
    /**
     * Shorter than this and the sound measure is least sure of itself, and no sung item of the
     * corpus is briefer: §1301 runs 290 s, and the shortest genuine sung section is well past a
     * minute. The same floor {@see SustainedSoundSongSections} uses to propose a song.
     */
    private const MINIMUM_SECONDS = 45.0;

    /**
     * Songs sit at or above 0.84 active against their run's own threshold and sermons, prayers,
     * readings and notices at or below 0.81 (10th and 90th percentiles, measured 2026-09-15).
     */
    private const MINIMUM_SUSTAINED_SHARE = 0.8;

    /**
     * Sung text arrives far slower than speech, because a line is held rather than said, and much
     * of it is never transcribed at all. §1301 reads 44 words a minute against 88 at the slowest
     * of the spoken sections, so the gap is wide and this sits in the middle of it.
     */
    private const MAXIMUM_WORDS_PER_MINUTE = 60.0;

    /**
     * The types a sung section can be hiding in.
     *
     * Song is excluded because it is already right; sermon and children's talk because a review
     * flag there disqualifies automatic extraction.
     *
     * @var list<ServiceSectionType>
     */
    private const CONSIDERED_TYPES = [
        ServiceSectionType::Welcome,
        ServiceSectionType::Prayer,
        ServiceSectionType::Notices,
        ServiceSectionType::BibleReading,
        ServiceSectionType::Other,
    ];

    public function __construct(private readonly RmsAnalysisService $rmsAnalysisService) {}

    /**
     * @param  string  $rmsLogContent  Raw contents of the rms_log_path artifact
     */
    public function apply(ServiceStructure $structure, string $rmsLogContent, ChurchServiceTranscript $transcript): ServiceStructure
    {
        if ($structure->isEmpty()) {
            return $structure;
        }

        $sound = $this->sustainedSound($rmsLogContent);

        if (! $sound instanceof SustainedSound) {
            return $structure;
        }

        $sections = $structure->sections;

        foreach ($structure->sections as $index => $section) {
            if ($this->readsAsSung($section, $sound, $transcript)) {
                $sections[$index] = $section->withReviewFlags([ServiceStructureValidator::FLAG_SECTION_READS_AS_SUNG]);
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

    private function readsAsSung(ServiceStructureSection $section, SustainedSound $sound, ChurchServiceTranscript $transcript): bool
    {
        if (! in_array($section->type, self::CONSIDERED_TYPES, true)) {
            return false;
        }

        $seconds = $section->endTime - $section->startTime;

        if ($seconds < self::MINIMUM_SECONDS) {
            return false;
        }

        if ($sound->share($section->startTime, $section->endTime) < self::MINIMUM_SUSTAINED_SHARE) {
            return false;
        }

        $words = str_word_count($transcript->sliceText($section->startTime, $section->endTime));

        return ($words / ($seconds / 60.0)) < self::MAXIMUM_WORDS_PER_MINUTE;
    }

    private function sustainedSound(string $rmsLogContent): ?SustainedSound
    {
        $samples = $this->rmsAnalysisService->extractRmsData($rmsLogContent);

        if ($samples === []) {
            return null;
        }

        try {
            $threshold = (float) $this->rmsAnalysisService->determineThreshold($rmsLogContent)['threshold'];
        } catch (\Throwable) {
            return null;
        }

        return SustainedSound::fromSamples($samples, $threshold);
    }
}
