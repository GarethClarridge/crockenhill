<?php

declare(strict_types=1);

namespace App\Services\DetectorEvaluation;

use App\Data\DetectorEntry;
use App\Data\DetectorSignal;
use App\Enums\DetectorSurface;
use App\Enums\SermonVideoQualityStatus;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\SectionPublication\SongPublicationBoundaryEvidenceService;
use App\Support\CanonicalJson;
use App\Support\DetectorAcceptanceThresholds;
use App\Support\DetectorCatalogue;
use App\Support\NewcombePairedDifference;
use App\Support\SectionReviewFlagPolicy;
use ReflectionClass;
use RuntimeException;

/**
 * Score a frozen case book against the predeclared thresholds: §4.3a H7's
 * `detectors:evaluate`.
 *
 * **What it scores, and what it cannot.** It reads *recorded* detector output
 * through the five adapters, exactly as `detectors:replay` does. So it can say
 * whether each confirmed defect is contained today and whether adjudicated
 * clean cases draw signals. It cannot say whether current code would decide the
 * same way: most runs were last detected before several detectors existed.
 * The report binds itself to `recorded_output` so that is never forgotten.
 *
 * **Three columns, three different evidence sources.**
 *
 * - *Regression* is fail-closed over every defective case, provisional or not:
 *   a fixture a candidate reintroduces fails regardless of how it was found.
 *   Contained means a matching signal that held, or any matching signal for a
 *   class whose ruled outcome is a flag ({@see DetectorEntry::$containedByFlag}).
 *   Otherwise a fired-but-unheld signal contained nothing.
 * - *False positives* use adjudicated clean cases only, one trial per service
 *   group, judged on the declared Wilson one-sided upper bound. Too few clean
 *   groups is `insufficient_evidence`, never a pass.
 * - *Recall* is H10's detector-negative sample, which has not been drawn. It
 *   is reported `not_measured`, and so no detector can be `accepted` yet.
 *
 * **Absence is not agreement.** A transcript or video surface never assessed on
 * the run, or a song section never assessed, makes the case `unassessable`
 * rather than missed or clean. A class with no emitting detector (fixed at
 * source, ruled out, unbuilt) is unassessable from recorded output: showing
 * that a fix prevents its class needs re-processing evidence.
 */
class DetectorEvaluation
{
    public function __construct(
        private readonly SectionReviewFlagSignals $sectionFlags,
        private readonly SuspectTranscriptBlockSignals $transcriptBlocks,
        private readonly VideoQualityVerdictSignals $videoVerdicts,
        private readonly SongPublicationReviewSignals $songReview,
        private readonly SongBoundaryEvidenceSignals $songBoundary,
    ) {}

    /**
     * @param  array<string, mixed>  $caseBook  A frozen case book artifact.
     * @param  list<string>  $splits  Splits to score.
     * @return array<string, mixed>
     */
    public function evaluate(array $caseBook, DetectorAcceptanceThresholds $thresholds, array $splits = ['regression', 'development']): array
    {
        $this->assertCaseBook($caseBook);

        /** @var list<array<string, mixed>> $cases */
        $cases = array_values(array_filter(
            $caseBook['cases'],
            static fn (array $case): bool => in_array($case['split'], $splits, true),
        ));
        $runs = MediaProcessingLog::query()
            ->whereIn('id', array_unique(array_map(static fn (array $case): int => $case['subject']['run'], $cases)))
            ->get()
            ->keyBy('id');

        $scored = [];

        foreach ($cases as $case) {
            $run = $runs->get($case['subject']['run']) ?? throw new RuntimeException(
                "Run {$case['subject']['run']} named by case [{$case['case_id']}] could not be found. "
                .'Check nothing has repointed the database, such as a Dusk run in progress.'
            );
            $scored[] = $this->scoreCase($case, $run);
        }

        $detectors = [];

        foreach ($this->byDetector($scored) as $detectorId => $detectorCases) {
            $detectors[$detectorId] = $this->scoreDetector(DetectorCatalogue::find($detectorId), $detectorCases, $thresholds);
        }

        ksort($detectors);

        return [
            'format' => 'crockenhill-detector-evaluation',
            'version' => 1,
            'splits' => $splits,
            'binding' => $this->binding($caseBook, $thresholds),
            'summary' => $this->summary($detectors),
            'detectors' => $detectors,
            'cases' => $scored,
        ];
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>
     */
    private function scoreCase(array $case, MediaProcessingLog $run): array
    {
        $entry = DetectorCatalogue::find($case['detector_id']);
        $base = [
            'case_id' => $case['case_id'],
            'detector_id' => $case['detector_id'],
            'service_group_key' => $case['service_group_key'],
            'truth' => $case['truth']['value'],
            'adjudicated' => $case['truth']['adjudicated'],
            'subject_held' => $this->subjectHeld($case['subject'], $run),
        ];

        if ($entry === null || $entry->surface === null) {
            return [...$base, 'outcome' => 'unassessable', 'reason' => 'detector_emits_nothing', 'match_level' => null];
        }

        $signals = $this->signalsForCase($entry, $case['subject'], $run);

        if ($signals === null) {
            return [...$base, 'outcome' => 'unassessable', 'reason' => 'surface_not_assessed', 'match_level' => null];
        }

        $matching = array_values(array_filter(
            $signals,
            fn (DetectorSignal $signal): bool => $signal->detectorId === $entry->id && $this->matchesSubject($signal, $case['subject']),
        ));
        $matchLevel = $this->matchLevel($matching, $case['subject']);

        if ($case['truth']['value'] === 'clean') {
            return [...$base, 'outcome' => $matching === [] ? 'correctly_silent' : 'false_positive', 'reason' => null, 'match_level' => $matchLevel];
        }

        $outcome = match (true) {
            $matching === [] => 'missed',
            array_filter($matching, static fn (DetectorSignal $signal): bool => $signal->held) !== [] => 'contained',
            $entry->containedByFlag => 'contained',
            default => 'flagged_unheld',
        };

        return [...$base, 'outcome' => $outcome, 'reason' => null, 'match_level' => $matchLevel];
    }

    /**
     * Whether anything currently withholds the case's subject, whichever
     * detector or person put the hold there.
     *
     * A detector miss and an uncontained defect are different findings: the
     * first is about the detector, the second is what the release gate cares
     * about. Many misses are held by an operator's `content_defect_hold`, which
     * contains the defect without crediting any detector. Null when the subject
     * has no hold state of its own (a run).
     *
     * @param  array{run: int, section: int|null, sermon: int|null}  $subject
     */
    private function subjectHeld(array $subject, MediaProcessingLog $run): ?bool
    {
        if ($subject['section'] !== null) {
            $held = ServiceSection::query()->whereKey($subject['section'])->value('needs_manual_review');

            return $held === null ? null : (bool) $held;
        }

        if ($subject['sermon'] !== null) {
            return $run->sermon?->video_quality_status === SermonVideoQualityStatus::Rejected;
        }

        return null;
    }

    /**
     * The detector's surface signals for one case, or null when that surface was
     * never assessed for the subject.
     *
     * @param  array{run: int, section: int|null, sermon: int|null}  $subject
     * @return list<DetectorSignal>|null
     */
    private function signalsForCase(DetectorEntry $entry, array $subject, MediaProcessingLog $run): ?array
    {
        $section = $subject['section'] !== null ? ServiceSection::query()->find($subject['section']) : null;

        return match ($entry->surface) {
            DetectorSurface::SectionReviewFlag => $section !== null
                ? $this->sectionFlags->forSection($run, $section)
                : $this->sectionFlags->for($run),
            DetectorSurface::SuspectTranscriptBlock => match (true) {
                $subject['section'] !== null => $section !== null ? $this->transcriptBlocks->forSection($run, $section) : null,
                $subject['sermon'] !== null => $this->transcriptBlocks->forSermon($run, $subject['sermon']),
                default => $this->transcriptBlocks->for($run),
            },
            DetectorSurface::VideoQualityVerdict => $this->videoVerdicts->for($run),
            DetectorSurface::SongPublicationReview => $section !== null
                ? $this->songReview->forSection($run, $section)
                : $this->songReview->for($run),
            DetectorSurface::SongBoundaryEvidence => $section !== null
                ? $this->songBoundary->forSection($run, $section)
                : $this->songBoundary->for($run),
            null => null,
        };
    }

    /**
     * A signal scoped to a section or sermon must name the case's own; a
     * run-level signal (a transcript block) matches any subject on its run,
     * and the match level says so.
     *
     * @param  array{run: int, section: int|null, sermon: int|null}  $subject
     */
    private function matchesSubject(DetectorSignal $signal, array $subject): bool
    {
        if ($subject['section'] !== null && $signal->sectionId !== null) {
            return $signal->sectionId === $subject['section'];
        }

        if ($subject['sermon'] !== null && $signal->sermonId !== null) {
            return $signal->sermonId === $subject['sermon'];
        }

        return true;
    }

    /**
     * @param  list<DetectorSignal>  $matching
     * @param  array{run: int, section: int|null, sermon: int|null}  $subject
     */
    private function matchLevel(array $matching, array $subject): ?string
    {
        if ($matching === []) {
            return null;
        }

        return match (true) {
            $subject['section'] !== null && $matching[0]->sectionId !== null => 'section',
            $subject['sermon'] !== null && $matching[0]->sermonId !== null => 'sermon',
            default => 'run',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $cases
     * @return array<string, mixed>
     */
    private function scoreDetector(?DetectorEntry $entry, array $cases, DetectorAcceptanceThresholds $thresholds): array
    {
        $severity = $entry?->severity;
        $ceiling = $severity !== null ? $thresholds->forSeverity($severity)['false_positive_ceiling'] ?? null : null;
        $regression = $this->regression($cases);
        $falsePositive = $this->falsePositive($cases, $ceiling, $thresholds->falsePositiveBoundZ());

        return [
            'status' => $entry?->status->value,
            'severity' => $severity?->value,
            'unit' => $entry?->unit->value,
            'regression' => $regression,
            'false_positive' => $falsePositive,
            'recall' => ['verdict' => 'not_measured', 'reason' => 'H10 detector-negative sample not drawn'],
            'review_burden' => ['verdict' => 'not_measured', 'reason' => 'needs an incumbent comparison on the H10 sample'],
            'acceptance' => match (true) {
                $regression['verdict'] === 'fail', $falsePositive['verdict'] === 'fail' => 'fail',
                default => 'not_established',
            },
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cases
     * @return array<string, mixed>
     */
    private function regression(array $cases): array
    {
        $defective = array_values(array_filter($cases, static fn (array $case): bool => $case['truth'] === 'defective'));
        $outcomes = array_count_values(array_column($defective, 'outcome'));

        return [
            'defective_cases' => count($defective),
            'outcomes' => $outcomes,
            'verdict' => match (true) {
                $defective === [] => 'no_defective_cases',
                ($outcomes['missed'] ?? 0) + ($outcomes['flagged_unheld'] ?? 0) > 0 => 'fail',
                ($outcomes['unassessable'] ?? 0) > 0 => 'unassessable',
                default => 'pass',
            },
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cases
     * @return array<string, mixed>
     */
    private function falsePositive(array $cases, ?float $ceiling, float $z): array
    {
        $clean = array_filter($cases, static fn (array $case): bool => $case['truth'] === 'clean');
        $groups = [];

        foreach ($clean as $case) {
            if (! $case['adjudicated'] || $case['outcome'] === 'unassessable') {
                continue;
            }

            $key = $case['service_group_key'];
            $groups[$key] = ($groups[$key] ?? false) || $case['outcome'] === 'false_positive';
        }

        $n = count($groups);
        $k = count(array_filter($groups));
        $upper = $n > 0 ? NewcombePairedDifference::wilson($k, $n, $z)[1] : null;

        return [
            'clean_groups' => $n,
            'false_positive_groups' => $k,
            'rate' => $n > 0 ? round($k / $n, 4) : null,
            'upper_bound' => $upper !== null ? round($upper, 4) : null,
            'ceiling' => $ceiling,
            'provisional_clean_cases' => count(array_filter($clean, static fn (array $case): bool => ! $case['adjudicated'])),
            'unassessable_clean_cases' => count(array_filter($clean, static fn (array $case): bool => $case['outcome'] === 'unassessable')),
            'verdict' => match (true) {
                $ceiling === null => 'reporting_only',
                $n === 0 => 'no_adjudicated_clean_cases',
                $upper <= $ceiling => 'pass',
                $k / $n > $ceiling => 'fail',
                default => 'insufficient_evidence',
            },
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $scored
     * @return array<string, list<array<string, mixed>>>
     */
    private function byDetector(array $scored): array
    {
        $grouped = [];

        foreach ($scored as $case) {
            $grouped[$case['detector_id']][] = $case;
        }

        return $grouped;
    }

    /**
     * @param  array<string, array<string, mixed>>  $detectors
     * @return array<string, mixed>
     */
    private function summary(array $detectors): array
    {
        return [
            'detectors' => count($detectors),
            'acceptance' => array_count_values(array_column($detectors, 'acceptance')),
            'regression' => array_count_values(array_map(static fn (array $d): string => $d['regression']['verdict'], $detectors)),
            'false_positive' => array_count_values(array_map(static fn (array $d): string => $d['false_positive']['verdict'], $detectors)),
        ];
    }

    /**
     * H6: everything whose change makes these metrics stale.
     *
     * @param  array<string, mixed>  $caseBook
     * @return array<string, mixed>
     */
    private function binding(array $caseBook, DetectorAcceptanceThresholds $thresholds): array
    {
        $policyFile = (new ReflectionClass(SectionReviewFlagPolicy::class))->getFileName();

        return [
            'evidence' => 'recorded_output',
            'evidence_caveat' => 'Stored decisions read through the adapters; they need not reflect current code.',
            'git_commit' => $this->gitCommit(),
            'catalogue_version' => DetectorCatalogue::Version,
            'thresholds_hash' => $thresholds->toArray()['hash'] ?? null,
            'case_book_hash' => $caseBook['case_book_hash'],
            'review_flag_policy_sha256' => is_string($policyFile) ? hash_file('sha256', $policyFile) : null,
            'song_evidence_version' => SongPublicationBoundaryEvidenceService::VERSION,
            'current_structure_model' => config('media-processing.service_structure.model'),
            'structure_model_note' => 'The model that produced each run\'s recorded structure is in that run\'s processing_fingerprint, not here.',
        ];
    }

    /**
     * Read from `.git` directly: shelling out to git fails under the parallel
     * test runner's differing file ownership.
     */
    private function gitCommit(): ?string
    {
        $head = @file_get_contents(base_path('.git/HEAD'));

        if (! is_string($head)) {
            return null;
        }

        $head = trim($head);

        if (! str_starts_with($head, 'ref: ')) {
            return $head;
        }

        $ref = substr($head, 5);
        $loose = @file_get_contents(base_path(".git/{$ref}"));

        if (is_string($loose)) {
            return trim($loose);
        }

        $packed = @file_get_contents(base_path('.git/packed-refs'));

        if (is_string($packed) && preg_match('/^([0-9a-f]{40}) '.preg_quote($ref, '/').'$/m', $packed, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $caseBook
     */
    private function assertCaseBook(array $caseBook): void
    {
        if (($caseBook['format'] ?? null) !== FreezeDetectorCaseBook::Format || ($caseBook['version'] ?? null) !== FreezeDetectorCaseBook::Version) {
            throw new RuntimeException('Not a frozen detector case book.');
        }

        $recorded = $caseBook['case_book_hash'] ?? null;
        $content = $caseBook;
        unset($content['case_book_hash']);

        if (! is_string($recorded) || ! hash_equals($recorded, CanonicalJson::hash($content))) {
            throw new RuntimeException('The case book does not match its recorded hash; refusing to score an edited book.');
        }

        if (($caseBook['inputs']['catalogue_version'] ?? null) !== DetectorCatalogue::Version) {
            throw new RuntimeException('The case book was frozen against another catalogue version; re-freeze it.');
        }
    }
}
