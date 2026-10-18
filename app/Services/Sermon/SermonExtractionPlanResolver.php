<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Actions\HoldSectionForContentReview;
use App\Data\ChurchServiceTranscript;
use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionType;
use App\Exceptions\OutputEdgeTimingsMissing;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\DeadFeedExcision;
use App\Services\ChurchService\OutputEdgeAnswers;
use App\Services\ChurchService\PublicationPlanValidator;
use App\Services\ChurchService\Structure\SermonContinuationScreen;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\Media\Audio\AudioTimeline;
use App\Services\Scripture\ScriptureReferenceResolver;
use App\Support\SermonAutoExtractionPolicy;
use App\Support\ServiceArtifactDisk;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/** Composes named sections upstream; execution resolves their current bounds without fallback. */
class SermonExtractionPlanResolver
{
    /**
     * "Let's pray" (1240) or "Let's bow our heads again in prayer together" (1197). A corpus
     * scan of every "pray" cue inside a reading the sermon could take found only these two
     * leader handovers among the reading's own words ("when you pray…", 2026-10-05).
     */
    private const PRAYER_HANDOVER = "/\\blet(?:'s| us) (?:pray\\b|[^.?!]*\\bin prayer\\b)/i";

    /**
     * Less of the reading than this after a handover is the reading ending into its prayer.
     */
    private const READING_AFTER_HANDOVER_SECONDS = 30.0;

    /** The longest gap between parts, holding no other section, that plays straight through (amended D1). */
    private const SILENT_GAP_SECONDS = 15.0;

    /** How far past the recording's end a section may run and be cut to that end. */
    private const SOURCE_END_OVERRUN_SECONDS = 1.0;

    /** A first section starting within this of the recording's start is what the recording opens with. */
    private const RECORDING_OPENS_WITH_SECTION_SECONDS = 5.0;

    /** The longest item between two readings that leaves them one group: a comment on the next one. */
    private const BETWEEN_READINGS_SECONDS = 90.0;

    /** Between sections walked back from the sermon, the longest stretch no section accounts for. */
    private const UNACCOUNTED_GAP_SECONDS = 30.0;

    /** A December service reading at least this many passages before its sermon reads lessons (a carol service). */
    private const LESSONS_READINGS = 4;

    public function __construct(
        private readonly ScriptureReferenceResolver $scriptureReferences,
        private readonly SermonContinuationScreen $continuations,
        private readonly PublicationPlanValidator $validator,
    ) {}

    /**
     * Recompute composition after detection or review. Keep a review decision only while
     * its section and evidence identity remains unchanged.
     *
     * @return array<string, mixed>
     */
    public function compose(MediaProcessingLog $log): array
    {
        $sections = $log->serviceSections()->orderBy('start_time')->orderBy('id')->get()->values()->all();
        $identity = $this->inputIdentity($log, $sections);
        $sermons = array_values(array_filter($sections, static fn (ServiceSection $section): bool => $section->section_type === ServiceSectionType::Sermon));
        $risks = [];
        $pendingEdgeEvidence = null;
        $selected = [];
        $sermon = count($sermons) === 1 ? $sermons[0] : null;
        if ($sermon === null) {
            $risks[] = ['kind' => 'sermon_membership_unresolved', 'detail' => 'Identify one sermon section before extraction.'];
        } else {
            $selected[] = $sermon;
            if (in_array(ServiceStructureValidator::FLAG_SERMON_CONTAINS_SUNG_SPAN, $sermon->metadata->reviewFlags ?? [], true)) {
                // The cut would carry singing no song section claims (885: run 949's closing hymn).
                $risks[] = ['kind' => 'sermon_contains_sung_span', 'detail' => 'The sermon section holds sustained singing that no song section claims, so the published sermon media would contain it.'];
            }
            foreach ($sections as $section) {
                if ($section->id !== $sermon->id && $this->continues($section, $sermon->id)) {
                    // An embedded continuation is already present; a partial overlap joins the sermon's span.
                    if ($section->start_time >= $sermon->start_time && $section->end_time <= $sermon->end_time) {
                        continue;
                    }
                    $selected[] = $section;
                }
            }
            usort($selected, static fn (ServiceSection $a, ServiceSection $b): int => $a->start_time <=> $b->start_time);
            $first = $selected[0];
            $last = $selected[count($selected) - 1];
            // Amended D1 (operator, 2026-10-11): the prayer directly before the sermon is part of
            // it, whatever comes before that prayer (936's song between reading and prayer).
            $preceding = array_values(array_filter($sections, static fn (ServiceSection $section): bool => $section->start_time < $first->start_time && ! in_array($section, $selected, true)));
            $prayerBefore = $preceding === [] ? null : $preceding[count($preceding) - 1];
            if ($prayerBefore?->section_type === ServiceSectionType::Prayer && $prayerBefore->end_time <= $first->start_time + 0.001) {
                $selected[] = $prayerBefore;
            }
            $reference = $sermon->metadata?->raw['sermon_reference'] ?? null;
            $readings = array_values(array_filter($sections, static fn (ServiceSection $section): bool => $section->section_type === ServiceSectionType::BibleReading && $section->start_time < $first->start_time));
            $membership = $this->scriptureReferences->sermonReadingMembership(
                is_string($reference) ? $reference : null,
                array_map(static fn (ServiceSection $reading): ?string => $reading->metadata?->readingReference, $readings),
            );
            // A reading running into a selected part is already partly in the cut: taking it as a
            // span of its own would cut its overlap twice, so whether it belongs is asked.
            $overlapsSelected = static fn (ServiceSection $reading): bool => array_any($selected, static fn (ServiceSection $part): bool => $reading->end_time > $part->start_time + 0.001 && $reading->start_time < $part->end_time - 0.001);
            $readingsBefore = $this->readingsDirectlyBefore($sections, $first);
            // A group reading without a passage, with another reading further back that nothing
            // chose, leaves which is the sermon's open (a "let us turn to…" typed as a reading).
            $undecided = array_any($readingsBefore, static fn (ServiceSection $reading): bool => $reading->metadata?->readingReference === null)
                && array_any($readings, static fn (ServiceSection $reading, int $index): bool => ! in_array($reading, $readingsBefore, true) && ! in_array($index, $membership['selected'], true));
            if ($readingsBefore !== [] && ! $undecided && ! $this->isServiceOfLessons($log, $readings) && ! array_any($readingsBefore, $overlapsSelected)) {
                // Operator ruling 2026-10-13: every reading directly before the sermon is the
                // sermon's, whichever passage it preaches (936: Acts 17, Romans 4 and 1 Peter 4
                // before a sermon on 1 Peter), as well as those reading its passage further back
                // (1250's Job 29 and 30, read apart from Job 31).
                $selectedReadings = array_values(array_filter($readings, static fn (ServiceSection $reading, int $index): bool => in_array($reading, $readingsBefore, true)
                    || in_array($index, $membership['selected'], true), ARRAY_FILTER_USE_BOTH));
                $membership = ['selected' => [], 'review' => false, 'could_be_cut' => array_values(array_unique([...$membership['could_be_cut'],
                    ...array_keys(array_filter($readings, static fn (ServiceSection $reading): bool => in_array($reading, $readingsBefore, true)))]))];
            } else {
                $selectedReadings = array_map(static fn (int $index): ServiceSection => $readings[$index], $membership['selected']);
            }
            if ($selectedReadings !== [] && ! array_any($selectedReadings, $overlapsSelected)) {
                // One reading holding the passage, or several reading it in parts (1250).
                array_push($selected, ...$selectedReadings);
            } elseif ($membership['review'] || $selectedReadings !== []) {
                // A reading sharing verses without holding the sermon's passage is a sermon reading
                // past it, or one part of a reference no set of readings covers: plausible, so
                // asked, never dropped.
                $risks[] = ['kind' => 'sermon_reading_membership_unresolved', 'detail' => 'Choose the sermon reading: references are missing or multiple readings are plausible.'];
            }
            foreach ($membership['could_be_cut'] as $index) {
                foreach ($this->prayerHandovers($log, $readings[$index]) as $cue) {
                    $risks[] = ['kind' => 'sermon_reading_contains_prayer_handover', 'detail' => sprintf('Reading %s hands over to prayer at %.3f–%.3fs ("%s"): is a prayer inside the reading?', $readings[$index]->metadata->readingReference ?? '#'.$readings[$index]->id, $cue['start'], $cue['end'], trim($cue['text'], " \"'"))];
                }
            }
            $following = array_values(array_filter($sections, static fn (ServiceSection $section): bool => $section->start_time >= $last->end_time && ! in_array($section, $selected, true)));
            $beforeSong = [];
            foreach ($following as $section) {
                if ($section->section_type === ServiceSectionType::Song) {
                    break;
                }
                $beforeSong[] = $section;
            }
            $prayers = array_values(array_filter($beforeSong, static fn (ServiceSection $section): bool => $section->section_type === ServiceSectionType::Prayer));
            if (count($prayers) === 1 && ($beforeSong[0] ?? null) === $prayers[0]) {
                $selected[] = $prayers[0];
            } elseif ($prayers !== []) {
                $risks[] = ['kind' => 'sermon_prayer_membership_unresolved', 'detail' => 'Choose the concluding prayer: intervening sections or multiple prayers make membership uncertain.'];
            }
            try {
                foreach ($this->uncoveredSpeech($log, $sections, $selected, $sermon->id) as $cue) {
                    $risks[] = ['kind' => 'sermon_uncovered_speech', 'detail' => sprintf('Speech outside identified sections at %.3f–%.3fs: %s', $cue['start'], $cue['end'], $cue['text'])];
                }
            } catch (OutputEdgeTimingsMissing $exception) {
                $pendingEdgeEvidence = $exception->getMessage();
            }
        }

        $review = $log->processing_metadata?->raw['sermon_composition_review'] ?? null;
        $answered = [];
        // Whether the sections were chosen by the rules or by an operator's review of these inputs:
        // only the first is evidence that the rules compose this sermon unaided.
        $selectionSource = 'rules';
        if (is_array($review) && ($review['input_identity'] ?? null) === $identity && is_array($review['selected_section_ids'] ?? null)) {
            $selectionSource = 'operator_review';
            $byId = collect($sections)->keyBy('id');
            $selected = [];
            foreach ($review['selected_section_ids'] as $id) {
                $section = is_int($id) ? $byId->get($id) : null;
                if (! $section instanceof ServiceSection) {
                    throw new InvalidArgumentException('Sermon composition names a section outside its source run');
                }
                $selected[] = $section;
            }
            if ($sermon !== null && ! in_array($sermon, $selected, true)) {
                throw new InvalidArgumentException('Sermon composition must include the identified sermon');
            }
            // The review answers the risks it was shown, not the input identity alone: a flag
            // such as a sung span is not part of that identity, so a risk raised after the review
            // is a new question (a review recorded before risks were kept answers none).
            $shown = array_map(self::riskKey(...), array_filter(is_array($review['answered_risks'] ?? null) ? $review['answered_risks'] : [], is_array(...)));
            $answered = array_values(array_filter($risks, static fn (array $risk): bool => in_array(self::riskKey($risk), $shown, true)));
            $risks = array_values(array_filter($risks, static fn (array $risk): bool => ! in_array(self::riskKey($risk), $shown, true)));
        }
        usort($selected, static fn (ServiceSection $a, ServiceSection $b): int => $a->start_time <=> $b->start_time);
        $composition = [
            'edge_word_timings_pending' => $pendingEdgeEvidence,
            'input_identity' => $identity,
            'selected_section_ids' => array_map(static fn (ServiceSection $section): int => $section->id, $selected),
            'selection_source' => $selectionSource,
            'sermon_section_id' => $sermon?->id,
            'bible_section_id' => collect($selected)->first(fn (ServiceSection $section): bool => $section->section_type === ServiceSectionType::BibleReading)?->id,
            'trailing_section_ids' => array_values(array_map(fn (ServiceSection $section): int => $section->id, array_filter($selected, fn (ServiceSection $section): bool => $section->section_type === ServiceSectionType::Prayer
                && $sermon !== null && $section->start_time >= $sermon->end_time))),
            'requires_review' => $risks !== [],
            'risks' => $risks,
            'answered_risks' => $answered,
            'method' => 'identified_sections',
            'decision' => $risks === [] ? 'section_exact' : 'review',
        ];
        $log->writeProcessingMetadata(static function (array $metadata) use ($composition): array {
            $metadata['sermon_composition'] = $composition;

            return $metadata;
        });
        if ($sermon !== null) {
            $metadata = $sermon->metadata?->toArray() ?? [];
            $metadata['sermon_boundary'] = $composition;
            $flags = array_values(array_diff($sermon->metadata->reviewFlags ?? [], [SermonAutoExtractionPolicy::COMPOSITION_REVIEW_FLAG]));
            if ($risks !== []) {
                $flags[] = SermonAutoExtractionPolicy::COMPOSITION_REVIEW_FLAG;
                $sermon->needs_manual_review = true;
            } elseif ($flags === [] && ($sermon->metadata->reviewFlags ?? []) !== []) {
                $sermon->needs_manual_review = false;
            }
            $metadata['review_flags'] = $flags;
            $sermon->metadata = ServiceSectionMetadata::fromArray($metadata);
            $sermon->save();
        }

        return $composition;
    }

    /**
     * Consecutive parts with a gap of at most {@see self::SILENT_GAP_SECONDS} that no other section
     * claims play straight through (amended D1, operator 2026-10-11): a cut over a pause of a few
     * seconds is a visible jump for nothing (992's 5.2 s, 995's 1.8 s). The earlier part takes the
     * gap, so the parts meet: no cut, but each part's sound is still treated as its own. Parts of
     * one sermon (it and its continuations) join into one span instead.
     *
     * @param  list<array{start_time: float, end_time: float}>  $spans
     * @param  array<int, ServiceSection>  $sections
     * @param  list<ServiceSection>  $selected
     * @return list<array{start_time: float, end_time: float}>
     */
    private function throughSilentGaps(array $spans, array $sections, array $selected): array
    {
        $sermonIds = array_map(static fn (ServiceSection $section): int => $section->id,
            array_filter($selected, static fn (ServiceSection $section): bool => $section->section_type === ServiceSectionType::Sermon));
        // Parts of one sermon are one voice: they join and share their sound treatment.
        $isSermonPart = fn (ServiceSection $section): bool => $section->section_type === ServiceSectionType::Sermon
            || array_any($sermonIds, fn (int $id): bool => $this->continues($section, $id));
        $joined = [];
        $parts = [];

        foreach ($spans as $index => $span) {
            $last = array_key_last($joined);
            $previous = $last === null ? null : $joined[$last];
            $claimed = $previous !== null && array_any($sections, static fn (ServiceSection $section): bool => ! in_array($section, $selected, true)
                && $section->start_time < $span['start_time'] && $section->end_time > $previous['end_time']);

            if ($last !== null && $previous !== null && self::SILENT_GAP_SECONDS + 0.001 >= $span['start_time'] - $previous['end_time'] && ! $claimed) {
                if ($isSermonPart($parts[$last]) && $isSermonPart($selected[$index])) {
                    $joined[$last]['end_time'] = $span['end_time'];

                    continue;
                }

                // Different items meet: the video plays through, and each keeps its own sound
                // treatment, which one span would share (Codex review, 2026-10-13).
                $joined[$last]['end_time'] = $span['start_time'];
            }

            $joined[] = $span;
            $parts[] = $selected[$index];
        }

        return $joined;
    }

    /**
     * Selected sections may overlap at a seam: overlap is benign, and the join through silent gaps
     * ends the earlier where the later begins (1300's readings, 2026-10-18). A section that starts
     * before the previous one or ends inside it would lose or reorder audio, so it is refused.
     */
    private static function nestsInPrevious(float $start, float $end, float $previousStart, float $previousEnd): bool
    {
        return $start < $previousStart || $end <= $previousEnd;
    }

    /**
     * What a review answered about a risk: its kind and the detail it was shown, which names the
     * reading, interval or text in doubt. The same kind about something else is a new question.
     *
     * @param  array<mixed>  $risk
     */
    private static function riskKey(array $risk): string
    {
        $kind = $risk['kind'] ?? null;
        $detail = $risk['detail'] ?? null;

        return (is_string($kind) ? $kind : '').'|'.(is_string($detail) ? $detail : '');
    }

    /**
     * Cues in a reading the sermon could take where the leader hands over to prayer with the
     * reading section still running after it: 1240's single "Job 36-37" reading held Job 36,
     * "Let's pray", the prayer and Job 37, and 1197's ran 38 s into the prayer after it (I2).
     * The words only ask; membership stays with the references. A handover in the reading's
     * closing seconds hands over to the prayer after it.
     *
     * @return list<array{start: float, end: float, text: string}>
     */
    private function prayerHandovers(MediaProcessingLog $log, ServiceSection $reading): array
    {
        $path = $log->serviceTranscriptPath();
        $raw = $path === null ? null : rescue(static fn (): ?string => Storage::disk(ServiceArtifactDisk::for($path))->get($path), null, false);

        if (! is_string($raw)) {
            return [];
        }

        $handovers = [];

        foreach (ChurchServiceTranscript::fromArray(json_decode($raw, true))->cues as $cue) {
            if ($cue['start'] >= $reading->start_time && $cue['end'] <= $reading->end_time - self::READING_AFTER_HANDOVER_SECONDS
                && preg_match(self::PRAYER_HANDOVER, str_replace('’', "'", $cue['text'])) === 1) {
                $handovers[] = ['start' => (float) $cue['start'], 'end' => (float) $cue['end'], 'text' => $cue['text']];
            }
        }

        return $handovers;
    }

    /** @param list<int> $sectionIds */
    public function reviewComposition(MediaProcessingLog $log, array $sectionIds, string $inputIdentity, int $userId): void
    {
        $current = $this->compose($log);
        if ($current['input_identity'] !== $inputIdentity) {
            throw new InvalidArgumentException('Sections or coverage evidence changed; reload before reviewing composition.');
        }
        if ($sectionIds === [] || count($sectionIds) !== count(array_unique($sectionIds))) {
            throw new InvalidArgumentException('Choose each selected section once.');
        }
        $sections = $log->serviceSections()->whereIn('id', $sectionIds)->get();
        if ($sections->count() !== count($sectionIds)) {
            throw new InvalidArgumentException('Composition contains a section outside this run.');
        }
        foreach ($sections as $section) {
            if (! in_array($section->section_type, [ServiceSectionType::Sermon, ServiceSectionType::BibleReading, ServiceSectionType::Prayer], true)
                && ! $this->continues($section, (int) $current['sermon_section_id'])) {
                throw new InvalidArgumentException('Select only the sermon reading, sermon parts and concluding prayer.');
            }
        }
        if (! in_array($current['sermon_section_id'], $sectionIds, true)) {
            throw new InvalidArgumentException('Composition must include the identified sermon.');
        }
        $previousStart = 0.0;
        $previousEnd = 0.0;
        foreach ($sections->sortBy('start_time') as $section) {
            $start = (float) $section->start_time;
            $end = (float) $section->end_time;
            if (! is_finite($start) || ! is_finite($end) || self::nestsInPrevious($start, $end, $previousStart, $previousEnd) || $end <= $start
                || ($log->duration !== null && $end > $log->duration + 0.001)) {
                throw new InvalidArgumentException('Invalid selected section bounds: unordered, nested or outside source');
            }
            $previousStart = $start;
            $previousEnd = $end;
        }
        $sermonParts = $sections->filter(fn (ServiceSection $section): bool => $section->id === $current['sermon_section_id']
            || $this->continues($section, (int) $current['sermon_section_id']));
        $sermonStart = (float) $sermonParts->min('start_time');
        $sermonEnd = (float) $sermonParts->max('end_time');
        $songStart = $log->serviceSections()->where('section_type', ServiceSectionType::Song)
            ->where('start_time', '>=', $sermonEnd)->min('start_time');
        foreach ($sections as $section) {
            if (($section->section_type === ServiceSectionType::BibleReading && $section->end_time > $sermonStart)
                || ($section->section_type === ServiceSectionType::Prayer
                    && ($section->start_time < $sermonEnd || ($songStart !== null && $section->end_time > $songStart)))) {
                throw new InvalidArgumentException('Select the reading before the sermon and the concluding prayer before the post-sermon song.');
            }
        }
        $answeredRisks = [...$current['answered_risks'], ...$current['risks']];
        $log->writeProcessingMetadata(static function (array $metadata) use ($sectionIds, $inputIdentity, $userId, $answeredRisks): array {
            $metadata['sermon_composition_review'] = [
                'selected_section_ids' => $sectionIds,
                'input_identity' => $inputIdentity,
                'answered_risks' => $answeredRisks,
                'coverage_resolved' => true,
                'reviewed_by_user_id' => $userId,
                'reviewed_at' => now()->toIso8601String(),
            ];

            return $metadata;
        });
        $this->compose($log);
    }

    /**
     * The group of readings directly before the sermon (operator ruling 2026-10-13): reached from
     * the sermon's first part back over the prayers and short items between them (936: a notice
     * and the prayer before the sermon), then on through readings and the short comments between
     * them (930's "500 years after Isaiah…"). Anything else ends it: a song, a talk, a prayer
     * between readings, or more than half a minute no section accounts for.
     *
     * @param  list<ServiceSection>|array<int, ServiceSection>  $sections  The run's sections in time order
     * @return list<ServiceSection>
     */
    private function readingsDirectlyBefore(array $sections, ServiceSection $first): array
    {
        $preceding = array_reverse(array_values(array_filter($sections, static fn (ServiceSection $section): bool => $section->end_time <= $first->start_time + 0.001)));
        $short = static fn (ServiceSection $section): bool => $section->end_time - $section->start_time <= self::BETWEEN_READINGS_SECONDS;
        $group = [];
        $after = (float) $first->start_time;

        foreach ($preceding as $section) {
            // Time no section accounts for could hold anything: it ends the group.
            if ($after - (float) $section->end_time > self::UNACCOUNTED_GAP_SECONDS) {
                break;
            }
            $after = (float) $section->start_time;

            if ($section->section_type === ServiceSectionType::BibleReading) {
                $group[] = $section;

                continue;
            }

            $passable = $group === []
                ? $section->section_type === ServiceSectionType::Prayer || ($section->section_type === ServiceSectionType::Other && $short($section))
                : $section->section_type === ServiceSectionType::Other && $short($section);

            if (! $passable) {
                break;
            }
        }

        return array_reverse($group);
    }

    /**
     * A carol service's readings are its lessons, not the sermon's (operator ruling 2026-10-13: 930
     * read five, 1196 six): a December service reading at least four passages before its sermon.
     *
     * @param  list<ServiceSection>  $readingsBeforeTheSermon
     */
    private function isServiceOfLessons(MediaProcessingLog $log, array $readingsBeforeTheSermon): bool
    {
        $date = $log->churchService?->date;

        return $date !== null && (int) $date->format('n') === 12 && count($readingsBeforeTheSermon) >= self::LESSONS_READINGS;
    }

    /**
     * Whether a section's review state lets its cut go ahead, low confidence aside where it cannot
     * question the cut (operator rulings 2026-10-13): a reading the composition cuts with its
     * sermon (pattern D: 1235 and 1346 were held on it, and both were the readings), and a sermon
     * the recording opens with (pattern B: seven evening recordings that are only the sermon,
     * 23–28 minutes from the preacher's first word, held for want of a reading to pair with).
     * A review that flag alone asked for goes with it.
     *
     * @param  array<int, string>  $flags
     * @param  ServiceSection|null  $firstSection  The run's first section in time
     */
    private function reviewStatePermitsTheCut(ServiceSection $section, array $flags, ?ServiceSection $firstSection): bool
    {
        $opensTheRecording = $section->section_type === ServiceSectionType::Sermon
            && $firstSection?->is($section) === true
            && (float) $section->start_time <= self::RECORDING_OPENS_WITH_SECTION_SECONDS;
        $needsReview = (bool) $section->needs_manual_review;

        if (($section->section_type === ServiceSectionType::BibleReading || $opensTheRecording)
            && in_array(ServiceStructureValidator::FLAG_LOW_CONFIDENCE, $flags, true)) {
            $flags = array_values(array_diff($flags, [ServiceStructureValidator::FLAG_LOW_CONFIDENCE]));
            $needsReview = $needsReview && $flags !== [];
        }

        return SermonAutoExtractionPolicy::reviewStatePermitsAutoExtraction($needsReview, $flags);
    }

    /**
     * @param  array{section_id: int, start_time: float, end_time: float}|null  $heldSpanAuthority
     * @return array{mode: 'single_span'|'concat_spans', source: 'service_sections', segments: list<array{start_time: float, end_time: float}>, metadata: array<string, mixed>}
     */
    public function resolve(MediaProcessingLog $processingLog, ?array $heldSpanAuthority = null): array
    {
        // Composed afresh, never read back: a stored composition keeps its identity when the
        // membership rules change (964 kept a cut without its reading). Only the operator's
        // review is reused, and compose() keeps that by identity.
        $composition = $this->compose($processingLog);
        $sections = $processingLog->serviceSections()->orderBy('start_time')->orderBy('id')->get()->values()->all();
        $byId = collect($sections)->keyBy('id');
        $spans = [];
        $held = [];
        $requiresReview = ($composition['requires_review'] ?? true) === true;
        $authority = $heldSpanAuthority ?? $processingLog->authorisedHeldSermonSpan();
        $previousStart = 0.0;
        $previousEnd = 0.0;
        $seen = [];
        $selectedSections = [];
        foreach ($composition['selected_section_ids'] ?? [] as $id) {
            $section = is_int($id) ? $byId->get($id) : null;
            if (! $section instanceof ServiceSection || in_array($id, $seen, true)) {
                throw new InvalidArgumentException('Sermon composition repeats a section or names one outside its source run');
            }
            $start = (float) $section->start_time;
            $end = (float) $section->end_time;
            // A section ending a fraction past the recording ends with it (1136, 1219 failed their cut).
            if ($processingLog->duration !== null && $end > $processingLog->duration && $end <= $processingLog->duration + self::SOURCE_END_OVERRUN_SECONDS) {
                $end = (float) $processingLog->duration;
            }
            if (! is_finite($start) || ! is_finite($end) || self::nestsInPrevious($start, $end, $previousStart, $previousEnd) || $end <= $start
                || ($processingLog->duration !== null && $end > $processingLog->duration + 0.001)) {
                throw new InvalidArgumentException('Invalid selected section bounds: unordered, nested or outside source');
            }
            $seen[] = $id;
            $selectedSections[] = $section;
            $previousStart = $start;
            $previousEnd = $end;
            $flags = $section->metadata->reviewFlags ?? [];
            $isHeld = HoldSectionForContentReview::isHeld($flags);
            $authorised = $isHeld && $authority !== null && $section->id === $authority['section_id']
                && abs($start - $authority['start_time']) < 0.001 && abs($end - $authority['end_time']) < 0.001
                && SermonAutoExtractionPolicy::reviewStatePermitsHeldSpanRepair($flags);
            if ($isHeld && ! $authorised) {
                $held[] = $id;
                $requiresReview = true;
            } elseif (! $authorised && ! $this->reviewStatePermitsTheCut($section, $flags, $sections[0] ?? null)) {
                $requiresReview = true;
            }
            $spans[] = ['start_time' => $start, 'end_time' => $end];
        }
        $spans = $this->throughSilentGaps($spans, $sections, $selectedSections);

        try {
            $sermon = $byId->get($composition['sermon_section_id'] ?? null);
            $cuePlan = app(CueSafeExtractionPlan::class)->forSpans($processingLog, $spans, sermonEnd: true,
                edgeAnswers: $sermon instanceof ServiceSection ? OutputEdgeAnswers::of($sermon, $selectedSections) : null);
        } catch (OutputEdgeTimingsMissing $exception) {
            // Nothing can be cut until the edge words are decoded, but the structure's own verdict
            // still stands: a re-cut may decode them first only when the structure needs no review.
            return ['mode' => 'single_span', 'source' => 'service_sections', 'segments' => [],
                'metadata' => [...$composition, 'requires_review' => true, 'reason' => 'edge_word_timings_missing',
                    'edge_word_timings_error' => $exception->getMessage(), 'cue_edge_widening' => [],
                    'structure_requires_review' => $requiresReview,
                    'structure_reason' => match (true) {
                        $held !== [] => 'sermon_section_content_held',
                        $requiresReview => 'sermon_composition_review',
                        default => null,
                    },
                    'structure_segments' => $spans,
                    'held_sermon_section_ids' => $held,
                    'held_span_authorised_section_id' => $authority['section_id'] ?? null]];
        }
        $excision = app(DeadFeedExcision::class)->apply($processingLog, $selectedSections, $cuePlan['segments']);
        $spans = $excision['segments'];
        // Only a plan whose final spans pass every check may be executed (S6).
        $violations = $spans === [] ? [] : $this->validator->validate($processingLog, $selectedSections, $spans, $cuePlan['cue_edge_widening']);
        $violated = static fn (string $kind): array => array_merge([], ...array_column(
            array_filter($violations, static fn (array $violation): bool => $violation['kind'] === $kind),
            'section_ids',
        ));
        $crossed = $violated('crosses_held_section');
        $requiresReview = $requiresReview || $violations !== [];

        return [
            'mode' => count($spans) > 1 ? 'concat_spans' : 'single_span',
            'source' => 'service_sections',
            'segments' => $spans,
            'metadata' => [
                ...$composition,
                'cue_edge_widening' => $cuePlan['cue_edge_widening'],
                'dead_feed_excisions' => $excision['dead_feed_excisions'],
                'strategy' => 'identified_sections',
                'requires_review' => $requiresReview,
                'reason' => match (true) {
                    $held !== [] => 'sermon_section_content_held',
                    $crossed !== [] => 'sermon_span_crosses_held_section',
                    $violated('edge_unresolved') !== [] => 'sermon_edge_unresolved',
                    $violated('operator_edge_refused') !== [] => 'sermon_operator_edge_refused',
                    $violations !== [] => 'sermon_plan_invalid',
                    $requiresReview => 'sermon_composition_review',
                    default => null,
                },
                'held_sermon_section_ids' => $held,
                'crossed_held_section_ids' => $crossed,
                'plan_violations' => $violations,
                'sermon_boundary' => $composition,
                'continuation_section_ids' => array_values(array_filter($seen, fn (int $id): bool => $id !== $composition['sermon_section_id'] && $byId->get($id) instanceof ServiceSection && $this->continues($byId->get($id), (int) $composition['sermon_section_id']))),
                'held_span_authorised_section_id' => $authority['section_id'] ?? null,
            ],
        ];
    }

    public function hasAutoExtractableSermonSection(MediaProcessingLog $processingLog): bool
    {
        $plan = $this->resolve($processingLog);

        return $plan['segments'] !== [] && ($plan['metadata']['requires_review'] ?? true) === false;
    }

    /**
     * A sermon part is either recorded by a marker or named by the detector's own notes on an
     * `other` section. Reading the notes here means no separate screening pass, and no
     * re-projection that drops the marker, can leave a part out of the cut.
     */
    private function continues(ServiceSection $section, int $sermonId): bool
    {
        if ($section->metadata?->sermonContinuation?->continues($sermonId) === true) {
            return true;
        }

        return $this->notedContinuation($section) !== null;
    }

    /** @return array{evidence: string, source: 'detector_notes'}|null */
    private function notedContinuation(ServiceSection $section): ?array
    {
        if ($section->section_type !== ServiceSectionType::Other) {
            return null;
        }

        $evidence = $this->continuations->evidence($section);

        return $evidence === null ? null : ['evidence' => $evidence, 'source' => 'detector_notes'];
    }

    /**
     * The identity {@see self::compose()} would record now: what a recorded composition's
     * `input_identity` is compared with to show its sections have not moved since.
     */
    public function currentInputIdentity(MediaProcessingLog $log): string
    {
        return $this->inputIdentity($log, $log->serviceSections()->orderBy('start_time')->orderBy('id')->get()->values()->all());
    }

    /**
     * What a composition, and the operator's review of it, is bound to: the sections the plan
     * depends on ({@see self::planDependencies()}) and the coverage evidence, not every section
     * in the run, so an edit elsewhere keeps a reviewed selection.
     *
     * @param  array<int, ServiceSection>  $sections
     */
    private function inputIdentity(MediaProcessingLog $log, array $sections): string
    {
        $deadFeed = app(DeadFeedExcision::class);

        return hash('sha256', (string) json_encode([
            'sections' => array_map(fn (ServiceSection $section): array => [
                $section->id, $section->section_type->value, (float) $section->start_time, (float) $section->end_time,
                $section->metadata?->readingReference, $section->metadata?->raw['sermon_reference'] ?? null,
                $section->metadata?->sermonContinuation?->toArray() ?? $this->notedContinuation($section),
            ], $this->planDependencies($sections)),
            'duration' => $log->duration,
            'transcript' => $this->evidenceHash($log->serviceTranscriptPath()),
            'audio_timeline' => $this->evidenceHash($log->audio_timeline_path),
            ...($deadFeed->identity($log) === null ? [] : ['dead_feed_input_identity' => $deadFeed->inputIdentity($log)]),
        ]));
    }

    /**
     * The sections {@see self::compose()} reads: every sermon part, every reading before the
     * sermon (membership has no window), and every section from the first of those to the first
     * song after the sermon, that song included — the prayer the plan may select and anything
     * a cut could widen into. A section moving into or out of that stretch changes the set.
     * Without exactly one sermon the plan cannot be scoped, so every section counts.
     *
     * @param  array<int, ServiceSection>  $sections
     * @return list<ServiceSection>
     */
    private function planDependencies(array $sections): array
    {
        $sections = array_values($sections);
        $sermons = array_values(array_filter($sections, static fn (ServiceSection $section): bool => $section->section_type === ServiceSectionType::Sermon));

        if (count($sermons) !== 1) {
            return $sections;
        }

        $parts = array_values(array_filter($sections, fn (ServiceSection $section): bool => $section->id === $sermons[0]->id || $this->continues($section, $sermons[0]->id)));
        $partsStart = min((float) $sermons[0]->start_time, ...array_map(static fn (ServiceSection $section): float => (float) $section->start_time, $parts));
        $partsEnd = max((float) $sermons[0]->end_time, ...array_map(static fn (ServiceSection $section): float => (float) $section->end_time, $parts));
        $from = min([$partsStart, ...array_map(
            static fn (ServiceSection $reading): float => (float) $reading->start_time,
            array_filter($sections, static fn (ServiceSection $section): bool => $section->section_type === ServiceSectionType::BibleReading && $section->start_time < $partsStart),
        )]);
        $songs = array_filter($sections, static fn (ServiceSection $section): bool => $section->section_type === ServiceSectionType::Song && $section->start_time >= $partsEnd);
        $until = $songs === [] ? INF : min(array_map(static fn (ServiceSection $song): float => (float) $song->start_time, $songs));

        return array_values(array_filter($sections, static fn (ServiceSection $section): bool => in_array($section, $parts, true)
            || ((float) $section->start_time <= $until && (float) $section->end_time > $from)));
    }

    private function evidenceHash(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }
        $disk = Storage::disk(ServiceArtifactDisk::for($path));
        if (! $disk->exists($path)) {
            return 'missing';
        }
        $raw = $disk->get($path);

        return is_string($raw) ? hash('sha256', $raw) : 'missing';
    }

    /**
     * Use timed words corroborated as speech only between identified parts of the same sermon.
     * Identified songs and other sections already cover their intentionally excluded content.
     *
     * @param  array<int, ServiceSection>  $sections
     * @param  list<ServiceSection>  $selected
     * @return list<array{start: float, end: float, text: string}>
     */
    private function uncoveredSpeech(MediaProcessingLog $log, array $sections, array $selected, int $sermonId): array
    {
        usort($selected, static fn (ServiceSection $a, ServiceSection $b): int => $a->start_time <=> $b->start_time);
        $gaps = [];
        foreach ($selected as $index => $section) {
            $next = $selected[$index + 1] ?? null;
            if ($next !== null && $section->end_time < $next->start_time
                && ($section->id === $sermonId || $this->continues($section, $sermonId))
                && ($next->id === $sermonId || $this->continues($next, $sermonId))) {
                $pair = app(CueSafeExtractionPlan::class)->forSpans($log, [
                    ['start_time' => (float) $section->start_time, 'end_time' => (float) $section->end_time],
                    ['start_time' => (float) $next->start_time, 'end_time' => (float) $next->end_time],
                ])['segments'];
                if (count($pair) === 2) {
                    $gaps[] = [$pair[0]['end_time'], $pair[1]['start_time']];
                }
            }
        }
        if ($gaps === []) {
            return [];
        }
        $before = $gaps[0][0];
        $after = $gaps[count($gaps) - 1][1];
        $path = $log->serviceTranscriptPath();
        $timelinePath = $log->audio_timeline_path;
        if ($path === null && $timelinePath === null) {
            return [];
        }
        if ($path === null || $timelinePath === null) {
            return [['start' => $before, 'end' => $after, 'text' => 'Required coverage evidence is missing.']];
        }
        $transcriptDisk = Storage::disk(ServiceArtifactDisk::for($path));
        $timelineDisk = Storage::disk(ServiceArtifactDisk::for($timelinePath));
        if (! $transcriptDisk->exists($path) || ! $timelineDisk->exists($timelinePath)) {
            return [['start' => $before, 'end' => $after, 'text' => 'Required coverage evidence is missing.']];
        }
        $raw = $transcriptDisk->get($path);
        $audio = $timelineDisk->get($timelinePath);
        if (! is_string($raw) || ! is_string($audio)) {
            return [['start' => $before, 'end' => $after, 'text' => 'Required coverage evidence is missing.']];
        }
        $transcript = ChurchServiceTranscript::fromArray(json_decode($raw, true));
        $timeline = AudioTimeline::fromJson($audio);
        $uncovered = [];
        foreach ($transcript->cues as $cue) {
            $pieces = [];
            foreach ($gaps as [$from, $to]) {
                $start = max($from, $cue['start']);
                $end = min($to, $cue['end']);
                if ($end > $start) {
                    $pieces[] = [$start, $end];
                }
            }
            foreach ($sections as $section) {
                $remaining = [];
                foreach ($pieces as [$from, $to]) {
                    if ($section->end_time <= $from || $section->start_time >= $to) {
                        $remaining[] = [$from, $to];

                        continue;
                    }
                    if ($section->start_time > $from) {
                        $remaining[] = [$from, (float) $section->start_time];
                    }
                    if ($section->end_time < $to) {
                        $remaining[] = [(float) $section->end_time, $to];
                    }
                }
                $pieces = $remaining;
            }
            foreach ($pieces as [$from, $to]) {
                if ($timeline->speechShare($from, $to) > 0) {
                    $uncovered[] = ['start' => $from, 'end' => $to, 'text' => $cue['text']];
                }
            }
        }

        return $uncovered;
    }
}
